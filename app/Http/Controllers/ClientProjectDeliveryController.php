<?php

namespace App\Http\Controllers;

use App\Events\ProjectStatusChanged;
use App\Http\Requests\ProjectDeliveryResponseRequest;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientProjectDeliveryController extends Controller
{
    public function respond(
        ProjectDeliveryResponseRequest $request,
        Project $project,
        CustomerLifecycleNotifier $notifier,
    )
    {
        $data = $request->validated();
        [$project, $previousStatus] = DB::transaction(function () use ($request, $project, $data) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($project->client_id !== $request->user()->client->id) {
                abort(404);
            }
            if ($project->estado !== 'revision') {
                throw ValidationException::withMessages([
                    'delivery' => 'Este proyecto no tiene una entrega pendiente de revisión.',
                ]);
            }

            $previousStatus = $project->estado;
            if ($data['action'] === 'request_changes') {
                ProjectMessage::create([
                    'project_id' => $project->id,
                    'user_id' => $request->user()->id,
                    'body' => "Solicitud de cambios en la entrega:\n".$data['comments'],
                ]);
                $project->update(['estado' => 'en_progreso']);
            } else {
                $project->update([
                    'estado' => 'completado',
                    'progreso' => 100,
                    'entregado_at' => now(),
                ]);
            }

            return [$project->refresh(), $previousStatus];
        });

        event(new ProjectStatusChanged($project, $previousStatus, $project->estado));

        if ($data['action'] === 'request_changes') {
            $notifier->notify(
                eventType: 'project_delivery_changes_requested',
                email: config('services.digitalpyme.solicitudes_email') ?: config('mail.from.address'),
                subject: "Cambios solicitados en {$project->nombre}",
                intro: 'El cliente solicitó cambios en la entrega del proyecto.',
                details: [
                    'Proyecto: '.$project->nombre,
                    'Cliente: '.$request->user()->name.' ('.$request->user()->email.')',
                    'Comentarios: '.$data['comments'],
                ],
                sendDatabase: false,
            );
        }

        return response()->json($project->load(['milestones', 'documents']));
    }
}
