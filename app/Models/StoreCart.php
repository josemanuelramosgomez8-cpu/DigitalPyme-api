<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCart extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'items',
        'currency',
        'last_activity_at',
        'reminders_sent',
        'last_reminder_at',
        'recovered_at',
        'communications_consented_at',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'last_activity_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'recovered_at' => 'datetime',
            'communications_consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'reminders_sent' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
