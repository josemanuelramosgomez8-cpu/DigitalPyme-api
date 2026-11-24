<?php

namespace App\Models;

use App\Notifications\StoreInventoryAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProduct extends Model
{
    protected $fillable = [
        'sku',
        'nombre',
        'descripcion',
        'categoria',
        'precio',
        'stock',
        'punto_reorden',
        'ubicacion_almacen',
        'activo',
        'image_path',
        'image_mime_type',
    ];

    protected $appends = ['alerta_reorden', 'imagen_url'];

    protected $hidden = ['image_path'];

    protected static function booted(): void
    {
        static::saving(function (StoreProduct $product) {
            $product->estado_stock = $product->stock === 0 ? 'agotado' : 'disponible';
        });

        static::created(function (StoreProduct $product) {
            if ($product->activo ?? true) {
                $product->notifyInventoryState(null);
            }
        });

        static::updated(function (StoreProduct $product) {
            if (! $product->wasChanged(['stock', 'punto_reorden', 'activo'])) {
                return;
            }

            $wasActive = $product->getOriginal('activo') ?? true;
            $previousState = (bool) $wasActive
                ? self::inventoryState((int) $product->getOriginal('stock'), (int) $product->getOriginal('punto_reorden'))
                : null;
            $product->notifyInventoryState($previousState);
        });
    }

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'stock' => 'integer',
            'punto_reorden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getAlertaReordenAttribute(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->punto_reorden;
    }

    private static function inventoryState(int $stock, int $reorderPoint): ?string
    {
        if ($stock === 0) {
            return 'out_of_stock';
        }

        return $stock <= $reorderPoint ? 'low_stock' : null;
    }

    private function notifyInventoryState(?string $previousState): void
    {
        if (! $this->activo) {
            return;
        }

        $state = self::inventoryState((int) $this->stock, (int) $this->punto_reorden);
        if (! $state || $state === $previousState) {
            return;
        }

        $notification = new StoreInventoryAlert(
            $this->id,
            $this->sku,
            $this->nombre,
            (int) $this->stock,
            (int) $this->punto_reorden,
            $state,
        );

        User::query()->where('is_admin', true)->each(
            fn (User $admin) => $admin->notify($notification),
        );
    }

    public function getImagenUrlAttribute(): ?string
    {
        return $this->image_path
            ? url("/api/store/products/{$this->id}/image?v=".($this->updated_at?->timestamp ?? time()))
            : null;
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class, 'product_id');
    }
}
