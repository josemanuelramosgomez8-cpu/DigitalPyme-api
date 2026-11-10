<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreCoupon extends Model
{
    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'active',
        'starts_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
