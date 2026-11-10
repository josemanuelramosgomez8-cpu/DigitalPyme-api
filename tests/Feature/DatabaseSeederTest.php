<?php

namespace Tests\Feature;

use App\Models\Servicio;
use App\Models\PortfolioProject;
use App\Models\Testimonio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_publishes_the_own_project_and_labeled_sample_concepts(): void
    {
        $this->seed();

        $this->assertDatabaseCount('portfolio_projects', 4);
        $this->assertDatabaseHas('portfolio_projects', [
            'nombre' => 'DigitalPyme',
            'categoria' => 'Proyecto propio',
            'resultado' => 'Catálogo de servicios y solicitudes de cotización',
            'descripcion_larga' => 'Sitio web propio de DigitalPyme para presentar servicios, orientar a negocios y recibir solicitudes de cotización.',
            'imagen' => '/portfolio/digitalpyme-catalogo.svg',
            'activo' => true,
        ]);
        $this->assertSame(3, PortfolioProject::where('categoria', 'Concepto de muestra')->count());
        $this->assertDatabaseHas('portfolio_projects', [
            'nombre' => 'Mercado Raíz',
            'categoria' => 'Concepto de muestra',
            'imagen' => '/portfolio/concepto-mercado-raiz.jpg',
            'activo' => true,
        ]);
        $this->assertSame(0, Testimonio::count());
    }

    public function test_running_database_seeder_preserves_existing_service_settings(): void
    {
        $this->seed();

        Servicio::where('nombre', 'Páginas web')->update([
            'descripcion' => 'Descripción personalizada',
            'descripcion_larga' => 'Alcance definido por el administrador.',
            'incluye' => ['Elemento personalizado'],
            'precio_desde' => 2750,
            'duracion' => '5 semanas',
            'activo' => false,
            'orden' => 20,
        ]);

        $this->seed();

        $this->assertDatabaseCount('servicios', 5);
        $this->assertDatabaseHas('servicios', [
            'nombre' => 'Páginas web',
            'descripcion' => 'Descripción personalizada',
            'descripcion_larga' => 'Alcance definido por el administrador.',
            'incluye' => json_encode(['Elemento personalizado']),
            'precio_desde' => 2750,
            'duracion' => '5 semanas',
            'activo' => false,
            'orden' => 20,
        ]);
    }

    public function test_running_database_seeder_fills_only_missing_own_project_details(): void
    {
        $this->seed();
        $project = PortfolioProject::where('nombre', 'DigitalPyme')->firstOrFail();
        $project->update([
            'descripcion_larga' => null,
            'desafio' => null,
            'solucion' => 'Solución ajustada por el administrador.',
            'entregables' => null,
            'tecnologias' => null,
        ]);

        $this->seed();

        $project->refresh();
        $this->assertSame(
            'Sitio web propio de DigitalPyme para presentar servicios, orientar a negocios y recibir solicitudes de cotización.',
            $project->descripcion_larga,
        );
        $this->assertNotEmpty($project->desafio);
        $this->assertSame('Solución ajustada por el administrador.', $project->solucion);
        $this->assertNotEmpty($project->entregables);
        $this->assertNotEmpty($project->tecnologias);
    }

    public function test_running_database_seeder_updates_legacy_sample_images_but_preserves_custom_images(): void
    {
        $this->seed();
        $market = PortfolioProject::where('nombre', 'Mercado Raíz')->firstOrFail();
        $market->update([
            'imagen' => '/portfolio/imagen-personalizada.jpg',
            'imagen_alt' => 'Imagen personalizada por el administrador.',
        ]);

        $this->seed();

        $market->refresh();
        $this->assertSame('/portfolio/imagen-personalizada.jpg', $market->imagen);
        $this->assertSame('Imagen personalizada por el administrador.', $market->imagen_alt);
        $this->assertDatabaseHas('portfolio_projects', [
            'nombre' => 'Casa Nómada',
            'imagen' => '/portfolio/concepto-casa-nomada.jpg',
            'imagen_alt' => 'Fotografía ilustrativa de una cabaña junto a un bosque. Imagen de Unsplash para un concepto de muestra.',
        ]);
    }

    public function test_seeded_services_have_the_approved_scopes_and_keep_quotes_without_prices(): void
    {
        $this->seed();

        $services = $this->getJson('/api/servicios')
            ->assertOk()
            ->json();

        $this->assertCount(5, $services);

        foreach ($services as $service) {
            $this->assertNotEmpty($service['descripcion_larga']);
            $this->assertNotEmpty($service['incluye']);
            $this->assertNull($service['precio_desde']);
            $this->assertNull($service['duracion']);
        }
    }
}
