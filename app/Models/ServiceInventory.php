<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceInventory extends Model
{
    protected $fillable = [
        'servicio_id',
        'subcategory_id',
        'available',
        'minimum',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'integer',
            'minimum' => 'integer',
        ];
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ServiceInventoryMovement::class);
    }
}
