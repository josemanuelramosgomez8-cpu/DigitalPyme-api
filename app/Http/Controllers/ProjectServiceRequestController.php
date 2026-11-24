<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectServiceRequest;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectServiceRequestController extends Controller
{
    public function clientStore(Request $request, Project $project, CustomerLifecycleNotifier $notifier)
    {
        abort_unless($project->client_id === $request->user()->client->id, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(ProjectServiceRequest::TYPES)],
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $serviceRequest = $project->serviceRequests()->create([
            ...$data,
            'status' => 'pendiente',
        ]);
        $project->loadMissing('client.user');
        $user = $project->client?->user;

        $notifier->notify(
            eventType: 'project_service_request_received',
            email: $user?->email ?? '',
            subject: "Recibimos tu solicitud para {$project->nombre}",
            intro: 'Registramos tu solicitud de servicio y el equipo la revisará. Te avisaremos cuando cambie su estado.',
            details: [
                'Proyecto: '.$project->nombre,
                'Solicitud: '.$this->typeLabel($serviceRequest->type),
                'Referencia: #'.$serviceRequest->id,
            ],
            userId: $user?->id,
            actionLabel: 'Ver mi proyecto',
            actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/?portal=1&tab=proyecto',
            resourceType: 'project_service_request',
            resourceId: $serviceRequest->id,
        );

        return response()->json($serviceRequest, 201);
    }

    public function adminUpdate(
        Request $request,
        ProjectServiceRequest $serviceRequest,
        CustomerLifecycleNotifier $notifier,
    ) {
        $data = $request->validate([
            'status' => ['required', Rule::in(['en_revision', 'resuelta', 'cerrada'])],
            'resolution' => ['nullable', Rule::in(ProjectServiceRequest::RESOLUTIONS)],
            'resolution_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'resolution_currency' => ['nullable', Rule::in(ProjectServiceRequest::CURRENCIES)],
            'response_message' => ['nullable', 'string', 'max:4000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $serviceRequest = DB::transaction(function () use ($serviceRequest, $data) {
            $serviceRequest = ProjectServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($serviceRequest->id);

            if (in_array($serviceRequest->status, ['resuelta', 'cerrada'], true)
                && $data['status'] !== $serviceRequest->status) {
                throw ValidationException::withMessages([
                    'status' => 'Una solicitud cerrada o resuelta no se puede reabrir ni cambiar de estado.',
                ]);
            }

            $isTerminal = in_array($data['status'], ['resuelta', 'cerrada'], true);
            $isRefund = in_array($data['resolution'] ?? null, ['reembolso_parcial', 'reembolso_total'], true);

            if ($isTerminal && (blank($data['resolution'] ?? null) || mb_strlen(trim($data['response_message'] ?? '')) < 5)) {
                throw ValidationException::withMessages([
                    'resolution' => 'Indica la resolución aplicada y una respuesta clara para el cliente.',
                ]);
            }

            if ($data['status'] === 'cerrada' && ($data['resolution'] ?? null) !== 'cierre_no_procede') {
                throw ValidationException::withMessages([
                    'resolution' => 'Una solicitud cerrada debe indicar que no procede.',
                ]);
            }

            if ($data['status'] === 'resuelta' && ($data['resolution'] ?? null) === 'cierre_no_procede') {
                throw ValidationException::withMessages([
                    'resolution' => 'Usa el estado cerrada cuando la solicitud no procede.',
                ]);
            }

            if ($isRefund && (
                ! isset($data['resolution_amount'])
                || (float) $data['resolution_amount'] <= 0
                || blank($data['resolution_currency'] ?? null)
            )) {
                throw ValidationException::withMessages([
                    'resolution_amount' => 'Registra el monto positivo y la moneda del reembolso acordado.',
                ]);
            }

            if (isset($data['resolution_amount']) !== isset($data['resolution_currency'])) {
                throw ValidationException::withMessages([
                    'resolution_currency' => 'El monto y la moneda deben registrarse juntos.',
                ]);
            }

            if ($data['status'] === 'en_revision') {
                $data['resolution'] = null;
                $data['resolution_amount'] = null;
                $data['resolution_currency'] = null;
                $data['response_message'] = null;
                $data['resolved_at'] = null;
            } else {
                $data['resolved_at'] = $serviceRequest->resolved_at ?? now();
            }

            $serviceRequest->update($data);

            return $serviceRequest->refresh();
        });

        $serviceRequest->loadMissing('project.client.user');
        $project = $serviceRequest->project;
        $user = $project->client?->user;
        $statusLabel = $serviceRequest->status === 'en_revision'
            ? 'En revisión'
            : ($serviceRequest->status === 'resuelta' ? 'Resuelta' : 'Cerrada');
        $details = [
            'Proyecto: '.$project->nombre,
            'Solicitud: '.$this->typeLabel($serviceRequest->type),
            'Estado: '.$statusLabel,
        ];

        if ($serviceRequest->resolution) {
            $details[] = 'Resolución: '.$this->resolutionLabel($serviceRequest->resolution);
        }
        if ($serviceRequest->resolution_amount !== null) {
            $details[] = 'Monto registrado: '.$serviceRequest->resolution_currency.' '.number_format((float) $serviceRequest->resolution_amount, 2, '.', ',');
        }

        $notifier->notify(
            eventType: 'project_service_request_updated',
            email: $user?->email ?? '',
            subject: "Actualización de tu solicitud #{$serviceRequest->id}",
            intro: $serviceRequest->response_message ?: "El estado de tu solicitud cambió a {$statusLabel}.",
            details: $details,
            userId: $user?->id,
            actionLabel: 'Ver mi proyecto',
            actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/?portal=1&tab=proyecto',
            resourceType: 'project_service_request',
            resourceId: $serviceRequest->id,
        );

        return response()->json($serviceRequest->makeVisible('internal_notes'));
    }

    private function typeLabel(string $type): string
    {
        return [
            'ajuste' => 'Ajuste o corrección',
            'incidencia' => 'Incidencia',
            'cancelacion' => 'Cancelación',
            'reembolso' => 'Reembolso',
            'reprogramacion' => 'Reprogramación',
        ][$type] ?? $type;
    }

    private function resolutionLabel(string $resolution): string
    {
        return [
            'ajuste_incluido' => 'Ajuste o corrección incluida',
            'reprogramacion' => 'Reprogramación acordada',
            'compensacion' => 'Compensación acordada',
            'reembolso_parcial' => 'Reembolso parcial registrado',
            'reembolso_total' => 'Reembolso total registrado',
            'cierre_no_procede' => 'Cierre: no procede',
        ][$resolution] ?? $resolution;
    }
}
