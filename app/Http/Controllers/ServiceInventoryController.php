<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ServiceInventory;
use App\Models\ServiceInventoryMovement;
use App\Models\Servicio;
use App\Models\ServiceSubcategory;
use App\Models\User;
use App\Notifications\ServiceInventoryAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ServiceInventoryController extends Controller
{
    public function index()
    {
        $services = Servicio::query()
            ->with([
                'category:id,nombre',
                'inventory.movements' => fn ($query) => $query
                    ->with([
                        'client:id,nombre,empresa',
                        'project:id,nombre',
                        'creator:id,name',
                    ])
                    ->latest()
                    ->limit(8),
            ])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
        $subcategories = ServiceSubcategory::query()
            ->where('activo', true)
            ->with([
                'category:id,nombre',
                'inventory.movements' => fn ($query) => $query
                    ->with([
                        'client:id,nombre,empresa',
                        'project:id,nombre',
                        'creator:id,name',
                    ])
                    ->latest()
                    ->limit(8),
            ])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
        $items = $services
            ->map(fn (Servicio $service) => $this->formatService($service))
            ->concat($subcategories->map(fn (ServiceSubcategory $subcategory) => $this->formatSubcategory($subcategory)))
            ->values();

        return response()->json($items);
    }

    public function updateMinimum(Request $request, string $resourceType, int $resourceId)
    {
        $data = $request->validate([
            'minimum' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        [$target, $inventory] = DB::transaction(function () use ($data, $resourceType, $resourceId) {
            $target = $this->lockTarget($resourceType, $resourceId);
            $inventory = ServiceInventory::firstOrCreate(
                $this->inventoryTargetKey($resourceType, $resourceId),
                ['available' => 0, 'minimum' => 0],
            );
            $inventory->update(['minimum' => $data['minimum']]);

            return [$target, $inventory->refresh()];
        });

        return response()->json($this->formatInventory(
            $target->fresh(['category']),
            $resourceType,
            $inventory
        ));
    }

    public function storeMovement(Request $request, string $resourceType, int $resourceId)
    {
        $data = $request->validate([
            'tipo' => ['required', 'string', 'in:entrada,salida'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $result = DB::transaction(function () use ($data, $request, $resourceType, $resourceId) {
            $target = $this->lockTarget($resourceType, $resourceId);
            $inventory = ServiceInventory::firstOrCreate(
                $this->inventoryTargetKey($resourceType, $resourceId),
                ['available' => 0, 'minimum' => 0],
            );
            $inventory = ServiceInventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $previousAvailable = $inventory->available;

            if ($data['tipo'] === 'salida' && $data['cantidad'] > $inventory->available) {
                throw ValidationException::withMessages([
                    'cantidad' => 'La salida no puede superar los cupos disponibles.',
                ]);
            }

            $clientId = $data['client_id'] ?? null;
            $projectId = $data['project_id'] ?? null;
            if ($projectId !== null) {
                $project = Project::query()->findOrFail($projectId);
                if ($clientId !== null && (int) $clientId !== $project->client_id) {
                    throw ValidationException::withMessages([
                        'project_id' => 'El proyecto seleccionado no pertenece al cliente indicado.',
                    ]);
                }
                $clientId = $project->client_id;
            } elseif ($clientId !== null) {
                Client::query()->findOrFail($clientId);
            }

            $inventory->available += $data['tipo'] === 'entrada'
                ? $data['cantidad']
                : -$data['cantidad'];
            $inventory->save();

            $movement = $inventory->movements()->create([
                'tipo' => $data['tipo'],
                'cantidad' => $data['cantidad'],
                'motivo' => $data['motivo'],
                'client_id' => $clientId,
                'project_id' => $projectId,
                'created_by' => $request->user()->id,
            ]);

            $crossedMinimum = $previousAvailable > $inventory->minimum
                && $inventory->available <= $inventory->minimum;
            $becameExhausted = $previousAvailable > 0 && $inventory->available === 0;
            if ($crossedMinimum || $becameExhausted) {
                $status = $inventory->available === 0 ? 'agotado' : 'cupo_bajo';
                Notification::send(
                    User::query()->where('is_admin', true)->get(),
                    new ServiceInventoryAlert(
                        $resourceType,
                        $target->id,
                        $target->nombre,
                        $inventory->available,
                        $inventory->minimum,
                        $status,
                    ),
                );
            }

            return [
                'target' => $target,
                'inventory' => $inventory->refresh(),
                'movement' => $movement->load([
                    'client:id,nombre,empresa',
                    'project:id,nombre',
                    'creator:id,name',
                ]),
            ];
        });

        return response()->json([
            ...$this->formatInventory(
                $result['target']->fresh(['category']),
                $resourceType,
                $result['inventory']
            ),
            'movement' => $this->formatMovement($result['movement']),
        ], 201);
    }

    private function formatService(Servicio $service): array
    {
        return [
            ...$this->formatInventory($service, 'servicio', $service->inventory),
            'movements' => $service->inventory?->movements
                ->map(fn (ServiceInventoryMovement $movement) => $this->formatMovement($movement))
                ->values() ?? [],
        ];
    }

    private function formatSubcategory(ServiceSubcategory $subcategory): array
    {
        return [
            ...$this->formatInventory($subcategory, 'subcategoria', $subcategory->inventory),
            'movements' => $subcategory->inventory?->movements
                ->map(fn (ServiceInventoryMovement $movement) => $this->formatMovement($movement))
                ->values() ?? [],
        ];
    }

    private function formatInventory(
        Servicio|ServiceSubcategory $target,
        string $resourceType,
        ?ServiceInventory $inventory
    ): array
    {
        $available = $inventory?->available ?? 0;
        $minimum = $inventory?->minimum ?? 0;

        return [
            'resource_type' => $resourceType,
            'resource_id' => $target->id,
            'name' => $target->nombre,
            'category' => $target->category?->nombre,
            'active' => $target->activo,
            'configured' => $inventory !== null,
            'available' => $available,
            'minimum' => $minimum,
            'status' => $inventory === null
                ? 'sin_configurar'
                : ($available === 0 ? 'agotado' : ($available <= $minimum ? 'cupo_bajo' : 'disponible')),
            'alert' => $inventory !== null && $available <= $minimum,
            'movements' => [],
        ];
    }

    private function lockTarget(string $resourceType, int $resourceId): Servicio|ServiceSubcategory
    {
        if ($resourceType === 'servicio') {
            return Servicio::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
        }

        return ServiceSubcategory::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
    }

    private function inventoryTargetKey(string $resourceType, int $resourceId): array
    {
        $key = $resourceType === 'servicio' ? 'servicio_id' : 'subcategory_id';

        return [$key => $resourceId];
    }

    private function formatMovement(ServiceInventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'tipo' => $movement->tipo,
            'cantidad' => $movement->cantidad,
            'motivo' => $movement->motivo,
            'client' => $movement->client?->only(['id', 'nombre', 'empresa']),
            'project' => $movement->project?->only(['id', 'nombre']),
            'created_by' => $movement->creator?->name,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }
}
