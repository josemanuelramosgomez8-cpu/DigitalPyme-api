<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    protected $table = 'proyectos';

    protected $fillable = [
        'titulo',
        'cliente',
        'categoria',
        'tipo',
        'descripcion',
        'imagen_url',
        'url_preview',
        'destacado',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'destacado' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}
