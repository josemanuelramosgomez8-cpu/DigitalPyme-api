<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Servicio extends Model
{
    protected $table = 'servicios';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_desde',
        'duracion',
        'slug',
        'descripcion_larga',
        'incluye',
        'etiqueta',
        'category_id',
        'subcategory_id',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'precio_desde' => 'decimal:2',
            'incluye' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id');
    }

    public function planificacion(): HasOne
    {
        return $this->hasOne(ServicePlanning::class, 'servicio_id');
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(ServiceInventory::class, 'servicio_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class, 'servicio_id');
    }
}