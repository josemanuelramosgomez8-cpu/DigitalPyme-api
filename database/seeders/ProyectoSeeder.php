<?php

namespace Database\Seeders;

use App\Models\Proyecto;
use Illuminate\Database\Seeder;

class ProyectoSeeder extends Seeder
{
    public function run(): void
    {
        Proyecto::firstOrCreate(
            ['titulo' => 'ByStore - Tienda en línea'],
            [
                'cliente' => 'ByStore',
                'categoria' => 'Comercio electrónico',
                'tipo' => 'real',
                'descripcion' => 'Tienda en línea para presentar productos y facilitar su compra.',
                'imagen_url' => null,
                'url_preview' => null,
                'destacado' => true,
                'activo' => true,
            ],
        );

        Proyecto::firstOrCreate(
            ['titulo' => 'DigitalPyme - Demo Plataforma'],
            [
                'cliente' => 'DigitalPyme',
                'categoria' => 'Plataforma digital',
                'tipo' => 'demo',
                'descripcion' => 'Concepto de muestra de una plataforma digital para presentar servicios y gestionar solicitudes.',
                'imagen_url' => null,
                'url_preview' => null,
                'destacado' => false,
                'activo' => true,
            ],
        );
    }
}
