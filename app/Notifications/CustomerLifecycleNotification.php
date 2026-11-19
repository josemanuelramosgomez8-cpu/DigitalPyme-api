<?php

namespace App\Notifications;

use App\Models\ProjectPayment;
use App\Models\StoreOrder;
use App\Support\PaymentReceiptPdf;
use App\Support\StoreOrderReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;

class CustomerLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $logId,
        public string $eventType,
        public string $subject,
        public string $intro,
        public array $details = [],
        public bool $sendEmail = true,
        public bool $sendDatabase = true,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public ?string $unsubscribeUrl = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        if ($this->sendEmail) {
            $channels[] = 'mail';
        }
        if ($this->sendDatabase && ! $notifiable instanceof AnonymousNotifiable) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject)
            ->markdown('emails.notifications.lifecycle', [
                'intro' => $this->intro,
                'details' => $this->details,
                'actionLabel' => $this->actionLabel,
                'actionUrl' => $this->actionUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);

        if ($this->actionLabel && $this->actionUrl) {
            $message->action($this->actionLabel, $this->actionUrl);
        }
        if ($this->unsubscribeUrl) {
            $message->line('Si no deseas recibir recordatorios, puedes darte de baja aquí: '.$this->unsubscribeUrl);
        }

        if ($this->resourceType === 'store_order' && $this->resourceId) {
            $order = StoreOrder::query()->with('items')->findOrFail($this->resourceId);
            $message->attachData(
                StoreOrderReceiptPdf::render($order),
                'pedido-digitalpyme-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT).'.pdf',
                ['mime' => 'application/pdf'],
            );
        } elseif ($this->resourceType === 'project_payment' && $this->resourceId) {
            $payment = ProjectPayment::query()
                ->with(['project.client', 'project.cotizacion', 'milestone'])
                ->findOrFail($this->resourceId);
            $message->attachData(
                PaymentReceiptPdf::render($payment),
                'recibo-digitalpyme-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT).'.pdf',
                ['mime' => 'application/pdf'],
            );
        }

        return $message;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event_type' => $this->eventType,
            'title' => $this->subject,
            'message' => $this->intro,
            'details' => $this->details,
            'action_url' => $this->actionUrl,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
