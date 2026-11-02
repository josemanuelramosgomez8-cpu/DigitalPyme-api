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

    public function test_database_seeder_publishes_only_the_verified_digitalpyme_own_project(): void
    {
        $this->seed();

        $this->assertDatabaseCount('portfolio_projects', 1);
        $this->assertDatabaseHas('portfolio_projects', [
            'nombre' => 'DigitalPyme',
            'categoria' => 'Proyecto propio',
            'resultado' => 'Catálogo de servicios y solicitudes de cotización',
            'imagen' => '/portfolio/digitalpyme-catalogo.svg',
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
