<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectPayment extends Model
{
    public const METHODS = ['bank_transfer', 'yape_plin', 'card'];

    public const MANUAL_METHODS = ['bank_transfer', 'yape_plin'];

    public const STATUSES = [
        'pendiente',
        'comprobante_enviado',
        'pagado',
        'rechazado',
        'fallido',
    ];

    protected $fillable = [
        'reference',
        'project_id',
        'milestone_id',
        'method',
        'provider',
        'provider_reference',
        'operation_number',
        'amount',
        'currency',
        'status',
        'paid_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $hidden = ['reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ProjectDocument::class, 'payment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewHistory(): HasMany
    {
        return $this->hasMany(ManualPaymentReview::class)->orderByDesc('id');
    }
}
