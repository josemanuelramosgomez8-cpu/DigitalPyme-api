<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Proyecto;
use App\Models\Servicio;
use App\Support\SolicitudNotifier;
use Database\Seeders\ProyectoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class PublicApiResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_endpoint_only_returns_active_projects_with_required_fields(): void
    {
        $this->seed(ProyectoSeeder::class);
        Proyecto::create([
            'titulo' => 'Proyecto oculto',
            'cliente' => 'Cliente',
            'categoria' => 'Web',
            'tipo' => 'demo',
            'descripcion' => 'Proyecto inactivo.',
            'activo' => false,
        ]);

        $this->getJson('/api/proyectos')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.titulo', 'ByStore - Tienda en línea')
            ->assertJsonPath('0.tipo', 'real')
            ->assertJsonPath('0.activo', true);
    }

    public function test_public_quote_accepts_cart_service_ids_and_saves_the_total_estimate(): void
    {
        $firstService = Servicio::create([
            'nombre' => 'Sitio web',
            'precio_desde' => 120.50,
            'activo' => true,
        ]);
        $secondService = Servicio::create([
            'nombre' => 'Branding',
            'precio_desde' => 79.50,
            'activo' => true,
        ]);
        $this->mock(SolicitudNotifier::class, function ($mock): void {
            $mock->shouldReceive('notify')->once()->andReturn([
                'email_enviado' => false,
                'whatsapp_url' => null,
            ]);
        });

        $this->postJson('/api/cotizaciones', [
            'nombre' => 'Ana Cliente',
            'empresa' => 'Acme',
            'contacto' => 'ana@example.com',
            'mensaje' => 'Necesito una cotización.',
            'servicios_ids' => [$firstService->id, $secondService->id],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.inversion_estimada', '200.00');

        $this->assertDatabaseHas('leads', [
            'nombre' => 'Ana Cliente',
            'empresa' => 'Acme',
            'email' => 'ana@example.com',
            'servicio' => 'Sitio web, Branding',
            'inversion_estimada' => 200,
        ]);
        $this->assertSame(1, Lead::count());
    }

    public function test_public_quote_rejects_unavailable_service_ids_with_spanish_validation(): void
    {
        $inactiveService = Servicio::create([
            'nombre' => 'Servicio inactivo',
            'activo' => false,
        ]);

        $this->postJson('/api/cotizaciones', [
            'nombre' => 'Ana Cliente',
            'contacto' => '+51 999 123 456',
            'servicios_ids' => [$inactiveService->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['servicios_ids.0']);
    }

    public function test_api_database_connection_errors_return_a_clean_service_unavailable_response(): void
    {
        Route::get('/api/testing/database-failure', function () {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
        });

        $this->getJson('/api/testing/database-failure')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Servicio temporalmente no disponible. Intenta nuevamente en unos momentos.',
                'data' => [],
            ]);
    }

    public function test_api_internal_errors_do_not_expose_exception_details(): void
    {
        Route::get('/api/testing/internal-failure', function () {
            throw new \RuntimeException('private SQL and credentials');
        });

        $this->getJson('/api/testing/internal-failure')
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'Servicio temporalmente no disponible. Intenta nuevamente en unos momentos.',
                'data' => [],
            ]);
    }
}
