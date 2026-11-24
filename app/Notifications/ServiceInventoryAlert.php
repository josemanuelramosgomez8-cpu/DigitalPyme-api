<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ServiceInventoryAlert extends Notification
{
    public function __construct(
        private readonly string $resourceType,
        private readonly int $resourceId,
        private readonly string $resourceName,
        private readonly int $available,
        private readonly int $minimum,
        private readonly string $status,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $exhausted = $this->status === 'agotado';

        return [
            'event_type' => 'service_inventory_alert',
            'title' => $exhausted ? 'Servicio sin cupos' : 'Servicio por agotarse',
            'message' => "{$this->resourceName} tiene {$this->available} cupos disponibles.",
            'details' => [
                'resource_type' => $this->resourceType,
                'resource_id' => $this->resourceId,
                'available' => $this->available,
                'minimum' => $this->minimum,
                'status' => $this->status,
            ],
            'action_url' => rtrim(config('services.digitalpyme.frontend_url'), '/').'/?admin=1',
            'created_at' => now()->toIso8601String(),
        ];
    }
}
