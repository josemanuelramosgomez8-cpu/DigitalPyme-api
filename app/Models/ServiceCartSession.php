<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCartSession extends Model
{
    protected $fillable = [
        'token',
        'items',
        'currency',
        'customer_name',
        'email',
        'phone',
        'communications_consented_at',
        'status',
        'last_activity_at',
        'reminders_sent',
        'last_reminder_at',
        'converted_at',
        'cotizacion_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'communications_consented_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'converted_at' => 'datetime',
            'reminders_sent' => 'integer',
        ];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }
}
