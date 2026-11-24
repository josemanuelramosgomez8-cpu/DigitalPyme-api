<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInventoryMovement extends Model
{
    protected $fillable = [
        'service_inventory_id',
        'tipo',
        'cantidad',
        'motivo',
        'client_id',
        'project_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ServiceInventory::class, 'service_inventory_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
