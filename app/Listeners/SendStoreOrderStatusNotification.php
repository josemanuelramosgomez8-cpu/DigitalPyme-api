<?php

namespace App\Listeners;

use App\Enums\StoreOrderStatus;
use App\Events\StoreOrderStatusChanged;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendStoreOrderStatusNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private CustomerLifecycleNotifier $notifier,
    ) {}

    public function handle(StoreOrderStatusChanged $event): void
    {
        $notifier = $this->notifier;
        $order = $event->order->loadMissing('client.user');
        $status = StoreOrderStatus::from($event->status);
        $details = [
            "Pedido #{$order->id}: {$status->label()}",
        ];
        if ($order->codigo_rastreo) {
            $details[] = "Código de seguimiento: {$order->codigo_rastreo}";
        }

        $notifier->notify(
            eventType: 'store_order_status_changed',
            email: $order->email_cliente ?: (string) $order->client?->user?->email,
            subject: "Actualización del pedido #{$order->id}",
            intro: "El estado de tu pedido cambió a {$status->label()}.",
            details: $details,
            userId: $order->client?->user_id,
            actionLabel: 'Ver mis pedidos',
            actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/portal?tab=pedidos',
            sendEmail: true,
            sendDatabase: true,
        );

        if ($status === StoreOrderStatus::Delivered) {
            $notifier->notify(
                eventType: 'store_order_post_sale',
                email: $order->email_cliente ?: (string) $order->client?->user?->email,
                subject: '¿Cómo fue tu experiencia con DigitalPyme?',
                intro: "Esperamos que disfrutes tu pedido #{$order->id}. Nos ayudaría conocer tu opinión.",
                details: [
                    'Déjanos una reseña y contáctanos si quieres conocer opciones complementarias para tu negocio.',
                ],
                userId: $order->client?->user_id,
                actionLabel: 'Compartir mi opinión',
                actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'#resenas',
                sendEmail: true,
                sendDatabase: true,
                sendAt: now()->addDays(7),
            );
        }
    }
}
