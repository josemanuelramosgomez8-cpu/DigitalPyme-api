<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;

class ProyectoController extends Controller
{
    public function index()
    {
        return response()->json(
            Proyecto::query()
                ->where('activo', true)
                ->orderByDesc('destacado')
                ->orderBy('id')
                ->get([
                    'id',
                    'titulo',
                    'cliente',
                    'categoria',
                    'tipo',
                    'descripcion',
                    'imagen_url',
                    'url_preview',
                    'destacado',
                    'activo',
                ])
        );
    }
}
