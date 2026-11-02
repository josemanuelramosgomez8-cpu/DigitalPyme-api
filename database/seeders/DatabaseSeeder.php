<?php

namespace Database\Seeders;

use App\Models\Servicio;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\PortfolioProject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $servicios = [
            [
                'nombre' => 'Páginas web',
                'descripcion' => 'Diseño y desarrollo de sitios web adaptables, con páginas acordadas, formulario de contacto y configuración SEO básica.',
                'descripcion_larga' => 'Planificamos y desarrollamos un sitio web adaptable a móviles y computadoras. El alcance se define según las páginas y necesidades acordadas para cada proyecto.',
                'incluye' => [
                    'Estructura y diseño adaptable para las páginas acordadas.',
                    'Desarrollo de las páginas incluidas en la propuesta.',
                    'Formulario de contacto.',
                    'Configuración SEO básica.',
                ],
            ],
            [
                'nombre' => 'Tiendas en línea',
                'descripcion' => 'Diseño y desarrollo de tiendas en línea con catálogo, carrito y gestión de pedidos.',
                'descripcion_larga' => 'Creamos una experiencia de compra en línea con catálogo de productos, carrito y flujo de pedidos. Los métodos de pago y envío se configuran según lo acordado para el proyecto.',
                'incluye' => [
                    'Catálogo de productos y páginas de producto.',
                    'Carrito y flujo de pedidos.',
                    'Configuración de los métodos de pago acordados.',
                    'Configuración de las opciones de envío acordadas.',
                ],
            ],
            [
                'nombre' => 'Branding',
                'descripcion' => 'Desarrollo de una identidad visual coherente con tu marca y las aplicaciones acordadas.',
                'descripcion_larga' => 'Definimos una línea visual para comunicar tu marca con coherencia. Las propuestas, revisiones y aplicaciones se detallan en el alcance aprobado antes de iniciar.',
                'incluye' => [
                    'Definición de una línea visual para la marca.',
                    'Logotipo y versiones en los formatos acordados.',
                    'Paleta de colores y selección tipográfica.',
                    'Aplicaciones de marca definidas en la propuesta.',
                ],
            ],
            [
                'nombre' => 'Marketing digital',
                'descripcion' => 'Diagnóstico y planificación de acciones digitales según tus objetivos y los canales acordados.',
                'descripcion_larga' => 'Revisamos los objetivos del negocio y preparamos un plan de acciones digitales. Los canales, la frecuencia y los entregables se definen en la propuesta de cada proyecto.',
                'incluye' => [
                    'Diagnóstico inicial de objetivos y presencia digital.',
                    'Plan de acciones para los canales acordados.',
                    'Frecuencia y entregables definidos en la propuesta.',
                ],
            ],
            [
                'nombre' => 'Mantenimiento',
                'descripcion' => 'Actualizaciones y revisiones técnicas de tu sitio web, según la frecuencia y el alcance acordados.',
                'descripcion_larga' => 'Mantenemos y revisamos técnicamente tu sitio web con la frecuencia definida para el servicio. El alcance específico se acuerda antes de iniciar.',
                'incluye' => [
                    'Actualizaciones contempladas en el alcance acordado.',
                    'Revisiones técnicas con la frecuencia definida en la propuesta.',
                ],
            ],
        ];

        foreach ($servicios as $i => $datosServicio) {
            $nombre = $datosServicio['nombre'];
            $category = ServiceCategory::firstOrCreate(
                ['slug' => Str::slug($nombre)],
                ['nombre' => $nombre, 'activo' => true, 'orden' => $i + 1],
            );
            $subcategory = $i === 0
                ? ServiceSubcategory::firstOrCreate(
                    ['category_id' => $category->id, 'slug' => 'landing-page'],
                    ['nombre' => 'Landing page', 'activo' => true, 'orden' => 1],
                )
                : null;
            $service = Servicio::firstOrCreate(
                ['nombre' => $nombre],
                [
                    ...$datosServicio,
                    'precio_desde' => null,
                    'duracion' => null,
                    'slug' => Str::slug($nombre),
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory?->id,
                    'activo' => true,
                    'orden' => $i + 1,
                ]
            );
            $changes = [];
            if (!$service->slug) $changes['slug'] = Str::slug($nombre);
            if (!$service->category_id) $changes['category_id'] = $category->id;
            if ($subcategory && !$service->subcategory_id) $changes['subcategory_id'] = $subcategory->id;
            if ($changes) $service->update($changes);
        }

        PortfolioProject::firstOrCreate(
            ['nombre' => 'DigitalPyme'],
            [
                'categoria' => 'Proyecto propio',
                'resultado' => 'Catálogo de servicios y solicitudes de cotización',
                'imagen' => '/portfolio/digitalpyme-catalogo.svg',
                'imagen_alt' => 'Ilustración original del sitio DigitalPyme con su catálogo de servicios.',
                'related_service_id' => Servicio::where('slug', 'paginas-web')->value('id'),
                'activo' => true,
                'orden' => 1,
            ],
        );

        $this->call(StoreDemoSeeder::class);
    }
}