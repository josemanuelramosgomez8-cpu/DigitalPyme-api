<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlanning extends Model
{
    protected $table = 'service_planning';

    protected $fillable = [
        'prioridad',
        'estandarizacion',
        'estado_preparacion',
        'tiempo_estimado_dias',
    ];

    protected function casts(): array
    {
        return [
            'tiempo_estimado_dias' => 'integer',
        ];
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }
}
