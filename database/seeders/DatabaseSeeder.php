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

        $digitalPymeDetails = [
            'descripcion_larga' => 'Sitio web propio de DigitalPyme para presentar servicios, orientar a negocios y recibir solicitudes de cotización.',
            'desafio' => 'Reunir en un mismo sitio la presentación de servicios, la atención de consultas y las herramientas que acompañan a los clientes durante sus proyectos.',
            'solucion' => 'Se desarrolló un sitio adaptable con catálogo de servicios, formulario y cotizador, agenda, reseñas y un portal para consultar proyectos, entregas, documentos y pagos.',
            'entregables' => [
                'Sitio web adaptable con catálogo de servicios y proyectos.',
                'Formulario de contacto, cotizador y agenda de reuniones.',
                'Portal de clientes con seguimiento de proyectos, entregas, documentos y pagos.',
            ],
            'tecnologias' => ['React', 'TypeScript', 'Laravel', 'API REST'],
        ];
        $digitalPymeProject = PortfolioProject::firstOrCreate(
            ['nombre' => 'DigitalPyme'],
            [
                ...$digitalPymeDetails,
                'categoria' => 'Proyecto propio',
                'resultado' => 'Catálogo de servicios y solicitudes de cotización',
                'imagen' => '/portfolio/digitalpyme-catalogo.svg',
                'imagen_alt' => 'Ilustración original del sitio DigitalPyme con su catálogo de servicios.',
                'related_service_id' => Servicio::where('slug', 'paginas-web')->value('id'),
                'activo' => true,
                'orden' => 1,
            ],
        );

        foreach ($digitalPymeDetails as $field => $value) {
            if ($digitalPymeProject->{$field} === null) {
                $digitalPymeProject->{$field} = $value;
            }
        }
        if ($digitalPymeProject->isDirty()) {
            $digitalPymeProject->save();
        }

        $sampleProjects = [
            [
                'nombre' => 'Mercado Raíz',
                'categoria' => 'Concepto de muestra',
                'resultado' => 'Concepto visual de tienda digital con catálogo y compra sencilla.',
                'descripcion_larga' => 'Ejercicio conceptual para ilustrar cómo podría organizarse una tienda en línea de productos naturales. No representa un negocio ni un proyecto entregado a un cliente.',
                'desafio' => 'Concepto: presentar distintas categorías de productos y hacer más claro el recorrido desde el catálogo hasta la consulta o compra.',
                'solucion' => 'Maqueta visual de una tienda adaptable con categorías, tarjetas de producto y un resumen del carrito.',
                'entregables' => ['Maqueta de inicio de tienda.', 'Diseño conceptual de catálogo y tarjetas de producto.', 'Vista conceptual del carrito.'],
                'tecnologias' => ['Diseño conceptual', 'Comercio electrónico'],
                'imagen' => '/portfolio/concepto-mercado-raiz.jpg',
                'imagen_alt' => 'Fotografía ilustrativa de productos frescos en un mercado. Imagen de Unsplash para un concepto de muestra.',
                'orden' => 2,
            ],
            [
                'nombre' => 'Casa Nómada',
                'categoria' => 'Concepto de muestra',
                'resultado' => 'Concepto visual de una página para alojamientos y reservas.',
                'descripcion_larga' => 'Ejercicio conceptual para mostrar una página de alojamientos con información clara y una llamada a consultar disponibilidad. No representa un negocio ni un proyecto entregado a un cliente.',
                'desafio' => 'Concepto: facilitar que una persona conozca un alojamiento, explore sus características y sepa cómo consultar fechas.',
                'solucion' => 'Maqueta visual adaptable con imagen principal, información del alojamiento, características y acceso a consulta.',
                'entregables' => ['Maqueta de página de alojamiento.', 'Diseño conceptual de características y galería.', 'Llamada visual a consultar disponibilidad.'],
                'tecnologias' => ['Diseño conceptual', 'Diseño web adaptable'],
                'imagen' => '/portfolio/concepto-casa-nomada.jpg',
                'imagen_alt' => 'Fotografía ilustrativa de una cabaña junto a un bosque. Imagen de Unsplash para un concepto de muestra.',
                'orden' => 3,
            ],
            [
                'nombre' => 'Estudio Lumen',
                'categoria' => 'Concepto de muestra',
                'resultado' => 'Concepto visual de una web para servicios profesionales.',
                'descripcion_larga' => 'Ejercicio conceptual para presentar servicios profesionales, explicar un proceso de trabajo y facilitar solicitudes de consulta. No representa un negocio ni un proyecto entregado a un cliente.',
                'desafio' => 'Concepto: organizar los servicios y explicar una propuesta profesional de forma directa.',
                'solucion' => 'Maqueta visual adaptable con presentación, tarjetas de servicios y un formulario conceptual de contacto.',
                'entregables' => ['Maqueta de página de servicios.', 'Diseño conceptual de tarjetas de servicio.', 'Llamada visual a solicitar una consulta.'],
                'tecnologias' => ['Diseño conceptual', 'Diseño web adaptable'],
                'imagen' => '/portfolio/concepto-estudio-lumen.jpg',
                'imagen_alt' => 'Fotografía ilustrativa de un espacio de trabajo moderno. Imagen de Unsplash para un concepto de muestra.',
                'orden' => 4,
            ],
        ];

        $legacySampleImages = [
            'Mercado Raíz' => [
                '/portfolio/concepto-mercado-raiz.svg',
                'Maqueta ilustrativa de una tienda de productos naturales, concepto de muestra.',
            ],
            'Casa Nómada' => [
                '/portfolio/concepto-casa-nomada.svg',
                'Maqueta ilustrativa de una página de alojamiento, concepto de muestra.',
            ],
            'Estudio Lumen' => [
                '/portfolio/concepto-estudio-lumen.svg',
                'Maqueta ilustrativa de una web de servicios profesionales, concepto de muestra.',
            ],
        ];

        foreach ($sampleProjects as $project) {
            $sampleProject = PortfolioProject::firstOrCreate(
                ['nombre' => $project['nombre']],
                [
                    ...$project,
                    'activo' => true,
                ],
            );

            [$legacyImage, $legacyAlt] = $legacySampleImages[$project['nombre']];
            if ($sampleProject->imagen === $legacyImage) {
                $sampleProject->imagen = $project['imagen'];
                if ($sampleProject->imagen_alt === $legacyAlt) {
                    $sampleProject->imagen_alt = $project['imagen_alt'];
                }
                $sampleProject->save();
            }
        }

        $this->call(StoreDemoSeeder::class);
    }
}