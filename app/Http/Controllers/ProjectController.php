<?php

namespace App\Http\Controllers;

use App\Events\ProjectStatusChanged;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index()
    {
        return response()->json(
            Project::query()
                ->with(['client:id,nombre,empresa', 'cotizacion:id,servicio,monto,estado'])
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:5000',
            'estado' => ['sometimes', Rule::in(Project::ESTADOS)],
            'progreso' => 'sometimes|integer|between:0,100',
            'fecha_inicio' => 'nullable|date',
            'fecha_entrega' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $project = Project::create($datos)->refresh();

        return response()->json(
            $project->load(['client:id,nombre,empresa', 'cotizacion:id,servicio,monto,estado']),
            201
        );
    }

    public function update(Request $request, Project $project)
    {
        $datos = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string|max:5000',
            'estado' => ['sometimes', 'required', Rule::in(Project::ESTADOS)],
            'progreso' => 'sometimes|required|integer|between:0,100',
            'fecha_inicio' => 'nullable|date',
            'fecha_entrega' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $previousStatus = $project->estado;
        $project->update($datos);
        if ($project->estado !== $previousStatus) {
            event(new ProjectStatusChanged($project->refresh(), $previousStatus, $project->estado));
        }

        return response()->json($project->load(['client:id,nombre,empresa', 'cotizacion:id,servicio,monto,estado']));
    }

    public function clientIndex(Request $request)
    {
        $client = $request->user()->client;

        return response()->json(
            $client->projects()
                ->with(['cotizacion:id,servicio,descripcion,monto,estado', 'milestones'])
                ->latest()
                ->get()
        );
    }

    public function clientShow(Request $request, Project $project)
    {
        $clientId = $request->user()->client->id;
        abort_unless($project->client_id === $clientId, 404);

        return response()->json(
            $project->load([
                'cotizacion:id,servicio,descripcion,monto,estado',
                'milestones',
                'documents:id,project_id,payment_id,original_name,tipo,mime_type,size,created_at',
                'payments:id,reference,project_id,milestone_id,method,provider,provider_reference,amount,currency,status,paid_at,created_at',
            ])
        );
    }

    public function adminMilestones(Project $project)
    {
        return response()->json($project->milestones()->orderBy('id')->get());
    }

    public function adminStoreMilestone(Request $request, Project $project)
    {
        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:2000',
            'monto' => 'nullable|numeric|min:0|max:99999999',
            'estado' => ['sometimes', Rule::in(['pendiente', 'pendiente_aprobacion'])],
        ]);

        return response()->json($project->milestones()->create($data), 201);
    }

    public function adminUpdateMilestone(
        Request $request,
        Project $project,
        ProjectMilestone $milestone,
        \App\Services\CustomerLifecycleNotifier $notifier,
    )
    {
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate([
            'titulo' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string|max:2000',
            'monto' => 'nullable|numeric|min:0|max:99999999',
            'estado' => ['sometimes', Rule::in(ProjectMilestone::ESTADOS)],
        ]);

        if (array_key_exists('estado', $data)) {
            $data['approved_at'] = $data['estado'] === 'aprobado' ? now() : null;
        }

        $previousStatus = $milestone->estado;
        $milestone->update($data);
        if (isset($data['estado']) && $milestone->estado !== $previousStatus) {
            $milestone->loadMissing('project.client.user');
            $user = $milestone->project->client?->user;
            if ($user) {
                $notifier->notify(
                    eventType: 'project_milestone_updated',
                    email: $user->email,
                    subject: 'Avance de proyecto: '.$milestone->project->nombre,
                    intro: "El hito «{$milestone->titulo}» cambió a {$milestone->estado}.",
                    details: array_values(array_filter([
                        $milestone->descripcion,
                        'Proyecto: '.$milestone->project->nombre,
                    ])),
                    userId: $user->id,
                    actionLabel: 'Ver avances',
                    actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/?portal=1&tab=proyecto',
                );
            }
        }

        return response()->json($milestone->refresh());
    }
}
