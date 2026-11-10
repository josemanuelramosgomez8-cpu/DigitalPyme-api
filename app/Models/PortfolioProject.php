<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioProject extends Model
{
    protected $fillable = [
        'nombre',
        'categoria',
        'resultado',
        'descripcion_larga',
        'desafio',
        'solucion',
        'entregables',
        'tecnologias',
        'imagen',
        'imagen_alt',
        'etiqueta',
        'related_service_id',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
            'entregables' => 'array',
            'tecnologias' => 'array',
        ];
    }

    public function relatedService()
    {
        return $this->belongsTo(Servicio::class, 'related_service_id');
    }
}
