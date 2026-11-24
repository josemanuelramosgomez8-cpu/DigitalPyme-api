<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectServiceRequest extends Model
{
    public const TYPES = [
        'ajuste',
        'incidencia',
        'cancelacion',
        'reembolso',
        'reprogramacion',
    ];

    public const STATUSES = [
        'pendiente',
        'en_revision',
        'resuelta',
        'cerrada',
    ];

    public const RESOLUTIONS = [
        'ajuste_incluido',
        'reprogramacion',
        'compensacion',
        'reembolso_parcial',
        'reembolso_total',
        'cierre_no_procede',
    ];

    public const CURRENCIES = ['PEN', 'USD'];

    protected $hidden = ['internal_notes'];

    protected $fillable = [
        'project_id',
        'type',
        'reason',
        'status',
        'resolution',
        'resolution_amount',
        'resolution_currency',
        'response_message',
        'internal_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolution_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
