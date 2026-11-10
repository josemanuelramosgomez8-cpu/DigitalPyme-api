<?php

namespace Tests\Feature;

use App\Models\PortfolioProject;
use App\Models\Servicio;
use App\Models\Testimonio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalogs_only_return_active_records(): void
    {
        $this->seed();
        Servicio::create([
            'nombre' => 'Servicio oculto',
            'activo' => false,
            'orden' => 0,
        ]);
        PortfolioProject::create([
            'nombre' => 'Proyecto oculto',
            'categoria' => 'Tiendas',
            'resultado' => 'Resultado',
            'imagen' => 'https://example.com/image.jpg',
            'activo' => false,
            'orden' => 0,
        ]);
        Testimonio::create([
            'contenido' => 'Testimonio oculto',
            'autor' => 'Persona',
            'cargo' => 'Cargo',
            'iniciales' => 'PE',
            'resultado' => '+1%',
            'resultado_descripcion' => 'resultado',
            'resultado_periodo' => 'en un mes',
            'activo' => false,
            'orden' => 0,
        ]);
        PortfolioProject::create([
            'nombre' => 'Proyecto verificado',
            'categoria' => 'Servicios',
            'resultado' => 'Caso autorizado',
            'imagen' => 'https://example.com/verified.jpg',
            'descripcion_larga' => 'Resumen del proyecto verificado.',
            'desafio' => 'El reto del proyecto.',
            'solucion' => 'La solución del proyecto.',
            'entregables' => ['Sitio informativo', 'Formulario'],
            'tecnologias' => ['Laravel', 'React'],
            'activo' => true,
            'orden' => 1,
        ]);
        Testimonio::create([
            'contenido' => 'Testimonio autorizado.',
            'autor' => 'Cliente verificado',
            'cargo' => 'Cliente',
            'iniciales' => 'CV',
            'resultado' => 'Experiencia positiva',
            'resultado_descripcion' => 'trabajo realizado',
            'resultado_periodo' => 'al finalizar',
            'activo' => true,
            'orden' => 1,
        ]);

        $this->getJson('/api/servicios')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonPath('0.nombre', 'Páginas web');

        $this->getJson('/api/portafolio')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonPath('0.nombre', 'DigitalPyme')
            ->assertJsonPath('1.nombre', 'Proyecto verificado')
            ->assertJsonPath('1.descripcion_larga', 'Resumen del proyecto verificado.')
            ->assertJsonPath('1.entregables.0', 'Sitio informativo')
            ->assertJsonPath('1.tecnologias.1', 'React')
            ->assertJsonPath('2.categoria', 'Concepto de muestra');

        $this->getJson('/api/testimonios')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.autor', 'Cliente verificado');
    }

    public function test_frontend_origin_is_allowed_to_read_the_public_api(): void
    {
        $this->withHeader('Origin', 'http://localhost:8443')
            ->getJson('/api/servicios')
            ->assertOk()
            ->assertHeader('access-control-allow-origin', 'http://localhost:8443');
    }

    public function test_only_administrators_can_manage_portfolio_projects_and_testimonials(): void
    {
        $projectData = [
            'nombre' => 'Proyecto de prueba',
            'categoria' => 'Tiendas',
            'resultado' => '+20% conversiones',
            'descripcion_larga' => 'Resumen para mostrar el detalle completo.',
            'desafio' => 'Desafío de demostración.',
            'solucion' => 'Solución de demostración.',
            'entregables' => ['Página web', 'Formulario'],
            'tecnologias' => ['React', 'Laravel'],
            'imagen' => 'https://example.com/project.jpg',
            'imagen_alt' => 'Tienda digital de prueba',
        ];
        $testimonialData = [
            'contenido' => 'Un gran servicio.',
            'autor' => 'Ana Pérez',
            'cargo' => 'Fundadora',
            'iniciales' => 'AP',
            'resultado' => '+30%',
            'resultado_descripcion' => 'más ventas',
            'resultado_periodo' => 'en seis meses',
        ];

        $this->postJson('/api/admin/portafolio', $projectData)->assertUnauthorized();
        $this->postJson('/api/admin/testimonios', $testimonialData)->assertUnauthorized();

        $admin = User::factory()->create(['is_admin' => true]);

        $project = $this->actingAs($admin)
            ->postJson('/api/admin/portafolio', $projectData)
            ->assertCreated()
            ->assertJsonPath('nombre', 'Proyecto de prueba')
            ->assertJsonPath('descripcion_larga', 'Resumen para mostrar el detalle completo.')
            ->assertJsonPath('entregables.0', 'Página web')
            ->json('id');

        $this->putJson("/api/admin/portafolio/{$project}", ['activo' => false])
            ->assertOk()
            ->assertJsonPath('activo', false)
            ->assertJsonPath('tecnologias.1', 'Laravel');
        $this->deleteJson("/api/admin/portafolio/{$project}")->assertOk();

        $testimonial = $this->postJson('/api/admin/testimonios', $testimonialData)
            ->assertCreated()
            ->assertJsonPath('autor', 'Ana Pérez')
            ->json('id');

        $this->putJson("/api/admin/testimonios/{$testimonial}", ['autor' => 'Ana P.'])
            ->assertOk()
            ->assertJsonPath('autor', 'Ana P.');
        $this->deleteJson("/api/admin/testimonios/{$testimonial}")->assertOk();
    }

    public function test_service_admin_crud_remains_available_to_administrators(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $service = $this->actingAs($admin)
            ->postJson('/api/admin/servicios', [
                'nombre' => 'Servicio nuevo',
                'precio_desde' => 500,
                'duracion' => 'Una semana',
                'activo' => true,
                'orden' => 10,
            ])
            ->assertCreated()
            ->json('id');

        $this->putJson("/api/admin/servicios/{$service}", ['precio_desde' => 700])
            ->assertOk()
            ->assertJsonPath('precio_desde', '700.00');
        $this->deleteJson("/api/admin/servicios/{$service}")->assertOk();
    }
}
