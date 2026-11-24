<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StoreInventoryAlert extends Notification
{
    public function __construct(
        private readonly int $productId,
        private readonly string $sku,
        private readonly string $name,
        private readonly int $stock,
        private readonly int $reorderPoint,
        private readonly string $status,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $title = $this->status === 'out_of_stock'
            ? 'Producto agotado'
            : 'Producto por agotarse';

        return [
            'event_type' => 'store_inventory_alert',
            'title' => $title,
            'message' => "{$this->name} ({$this->sku}) tiene {$this->stock} unidades.",
            'details' => [
                'product_id' => $this->productId,
                'sku' => $this->sku,
                'stock' => $this->stock,
                'reorder_point' => $this->reorderPoint,
                'status' => $this->status,
            ],
            'action_url' => rtrim(config('services.digitalpyme.frontend_url'), '/').'/?admin=1',
            'created_at' => now()->toIso8601String(),
        ];
    }
}
