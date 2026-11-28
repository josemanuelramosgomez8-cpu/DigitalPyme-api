<?php

namespace App\Http\Controllers;

use App\Events\ProjectStatusChanged;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Services\CustomerLifecycleNotifier;
use App\Support\AdminJsonPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = AdminJsonPagination::paginate(
            Project::query()
                ->with([
                    'client:id,nombre,empresa,telefono',
                    'cotizacion:id,servicio,monto,estado',
                    'brief:id,project_id,answers,submitted_at',
                    'serviceRequests' => fn ($query) => $query->latest(),
                    'payments' => fn ($query) => $query
                        ->select(['id', 'project_id', 'currency', 'status', 'amount', 'method', 'reviewed_by', 'reviewed_at'])
                        ->orderByDesc('id'),
                ])
                ->latest(),
            $request,
        );

        collect($projects->items())->each(function (Project $project) {
            $project->makeVisible(['recepcion_checklist', 'notas_preparacion']);
            $project->serviceRequests->each->makeVisible('internal_notes');
            $project->payments->each->makeVisible(['reviewed_by', 'reviewed_at']);
        });

        return AdminJsonPagination::respond($projects);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:5000',
            'estado' => ['sometimes', Rule::in(['planificacion'])],
            'progreso' => 'sometimes|integer|between:0,100',
            'fecha_inicio' => 'nullable|date',
            'fecha_entrega' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $project = Project::create($datos)->refresh();

        return response()->json(
            $project->load(['client:id,nombre,empresa,telefono', 'cotizacion:id,servicio,monto,estado'])
                ->makeVisible(['recepcion_checklist', 'notas_preparacion']),
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
            'recepcion_checklist' => ['sometimes', 'array:'.implode(',', Project::RECEPCION_CHECKLIST_KEYS)],
            'recepcion_checklist.alcance_confirmado' => ['sometimes', 'boolean'],
            'recepcion_checklist.brief_revisado' => ['sometimes', 'boolean'],
            'recepcion_checklist.insumos_recibidos' => ['sometimes', 'boolean'],
            'recepcion_checklist.accesos_coordinados' => ['sometimes', 'boolean'],
            'notas_preparacion' => ['nullable', 'string', 'max:5000'],
            'modalidad_entrega' => ['nullable', Rule::in(['infra_cliente', 'gestionada'])],
            'url_entregable' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'resumen_entrega' => ['nullable', 'string', 'max:3000'],
        ]);

        [$project, $previousStatus] = DB::transaction(function () use ($project, $datos) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $previousStatus = $project->estado;
            $nextStatus = $datos['estado'] ?? $project->estado;

            if (array_key_exists('recepcion_checklist', $datos)) {
                $datos['recepcion_checklist'] = array_replace(
                    array_fill_keys(Project::RECEPCION_CHECKLIST_KEYS, false),
                    $project->recepcion_checklist ?? [],
                    $datos['recepcion_checklist'],
                );
            }

            $checklist = array_replace(
                array_fill_keys(Project::RECEPCION_CHECKLIST_KEYS, false),
                $project->recepcion_checklist ?? [],
                $datos['recepcion_checklist'] ?? [],
            );

            if (
                $nextStatus === 'en_progreso'
                && $previousStatus !== 'en_progreso'
                && blank($project->recepcion_confirmada_at)
            ) {
                $missingRequirements = [];
                foreach (Project::RECEPCION_CHECKLIST_KEYS as $key) {
                    if (! ($checklist[$key] ?? false)) {
                        $missingRequirements["recepcion_checklist.{$key}"] = [
                            'Completa el checklist de recepción antes de iniciar la preparación.',
                        ];
                    }
                }
                if ($missingRequirements !== []) {
                    throw ValidationException::withMessages($missingRequirements);
                }

                if (! $project->payments()
                    ->where('status', 'pagado')
                    ->whereNotNull('paid_at')
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'payment' => 'Confirma al menos un pago del proyecto antes de iniciar la preparación.',
                    ]);
                }

                $datos['recepcion_checklist'] = $checklist;
                $datos['recepcion_confirmada_at'] = now();
            }

            if ($nextStatus === 'revision' && $previousStatus !== 'revision') {
                if ($previousStatus !== 'en_progreso') {
                    throw ValidationException::withMessages([
                        'estado' => 'Solo puedes enviar a revisión un servicio que esté en preparación.',
                    ]);
                }

                $deliveryMode = array_key_exists('modalidad_entrega', $datos)
                    ? $datos['modalidad_entrega']
                    : $project->modalidad_entrega;
                $deliveryUrl = array_key_exists('url_entregable', $datos)
                    ? $datos['url_entregable']
                    : $project->url_entregable;
                $deliverySummary = array_key_exists('resumen_entrega', $datos)
                    ? $datos['resumen_entrega']
                    : $project->resumen_entrega;
                $hasProjectFile = $project->documents()->where('tipo', 'documento')->exists();

                $deliveryErrors = [];
                if (! $deliveryMode) {
                    $deliveryErrors['modalidad_entrega'] = [
                        'Selecciona cómo se implementará o entregará el servicio.',
                    ];
                }
                if (blank($deliverySummary)) {
                    $deliveryErrors['resumen_entrega'] = [
                        'Incluye un resumen de lo que recibirá el cliente.',
                    ];
                }
                if (blank($deliveryUrl) && ! $hasProjectFile) {
                    $deliveryErrors['url_entregable'] = [
                        'Agrega un enlace seguro o sube un entregable al proyecto antes de enviarlo a revisión.',
                    ];
                }
                if ($deliveryErrors !== []) {
                    throw ValidationException::withMessages($deliveryErrors);
                }

                $datos['entrega_enviada_at'] = now();
                $datos['progreso'] = max($datos['progreso'] ?? $project->progreso, 90);
            }

            if ($nextStatus === 'completado') {
                if ($previousStatus !== 'revision' && $previousStatus !== 'completado') {
                    throw ValidationException::withMessages([
                        'estado' => 'El servicio debe enviarse a revisión antes de marcarlo como completado.',
                    ]);
                }
                $datos['progreso'] = 100;
                $datos['entregado_at'] ??= $project->entregado_at ?? now();
            }

            $project->update($datos);

            return [$project->refresh(), $previousStatus];
        });

        if ($project->estado !== $previousStatus) {
            event(new ProjectStatusChanged($project, $previousStatus, $project->estado));
        }

        $project->load([
            'client:id,nombre,empresa,telefono',
            'cotizacion:id,servicio,monto,estado',
            'brief:id,project_id,answers,submitted_at',
            'serviceRequests' => fn ($query) => $query->latest(),
            'payments' => fn ($query) => $query
                ->select(['id', 'project_id', 'currency', 'status', 'amount', 'method'])
                ->orderByDesc('id'),
        ]);
        $project->serviceRequests->each->makeVisible('internal_notes');

        return response()->json(
            $project->makeVisible(['recepcion_checklist', 'notas_preparacion'])
        );
    }

    public function clientIndex(Request $request)
    {
        $client = $request->user()->client;

        return response()->json(
            $client->projects()
                ->with(['cotizacion:id,servicio,descripcion,monto,estado', 'milestones', 'serviceRequests'])
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
                'serviceRequests' => fn ($query) => $query->latest(),
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
        CustomerLifecycleNotifier $notifier,
    ) {
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
