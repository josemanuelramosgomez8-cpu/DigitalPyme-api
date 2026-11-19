<?php

namespace App\Listeners;

use App\Events\ProjectStatusChanged;
use App\Models\Project;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class SendProjectStatusNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private CustomerLifecycleNotifier $notifier,
    ) {}

    public function handle(ProjectStatusChanged $event): void
    {
        $notifier = $this->notifier;
        $project = $event->project->loadMissing('client.user');
        $user = $project->client?->user;
        if (! $user) {
            return;
        }

        if ($event->status === 'revision') {
            $notifier->notify(
                eventType: 'project_delivery_ready',
                email: $user->email,
                subject: "Tu proyecto {$project->nombre} está listo para revisión",
                intro: 'Publicamos una entrega para que puedas revisarla y aprobarla o solicitar cambios desde el portal.',
                details: ['Proyecto: '.$project->nombre],
                userId: $user->id,
                actionLabel: 'Revisar entrega',
                actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/?portal=1&tab=proyecto',
            );

            return;
        }

        $notifier->notify(
            eventType: $event->status === 'completado' ? 'project_completed' : 'project_status_changed',
            email: $user->email,
            subject: $event->status === 'completado'
                ? "Proyecto completado: {$project->nombre}"
                : "Avance de tu proyecto: {$project->nombre}",
            intro: "El estado de tu proyecto cambió a {$event->status}.",
            details: [
                'Proyecto: '.$project->nombre,
                "Progreso: {$project->progreso} %",
            ],
            userId: $user->id,
            actionLabel: 'Ver mi proyecto',
            actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/?portal=1&tab=proyecto',
        );

        if ($event->status === 'completado') {
            $scheduledAt = now()->addDays(7);
            $firstCompletion = Project::query()
                ->whereKey($project->id)
                ->whereNull('post_sale_scheduled_at')
                ->update(['post_sale_scheduled_at' => $scheduledAt]);

            if ($firstCompletion) {
                $notifier->notify(
                    eventType: 'project_post_sale',
                    email: $user->email,
                    subject: '¿Cómo fue tu experiencia con DigitalPyme?',
                    intro: 'Gracias por confiar en nosotros. Nos encantaría conocer tu opinión y ayudarte con el siguiente paso de tu negocio.',
                    details: [
                        'Puedes compartir una reseña o consultar opciones complementarias para tu proyecto.',
                    ],
                    userId: $user->id,
                    actionLabel: 'Compartir mi opinión',
                    actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'#resenas',
                    sendAt: $scheduledAt,
                );
            }
        }
    }
}
