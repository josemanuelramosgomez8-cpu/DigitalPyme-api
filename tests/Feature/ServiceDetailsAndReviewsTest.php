<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceDetailsAndReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_review_feed_only_returns_approved_reviews_for_active_services_without_private_email(): void
    {
        $activeService = Servicio::create([
            'nombre' => 'Landing page',
            'slug' => 'landing-page',
            'activo' => true,
        ]);
        $inactiveService = Servicio::create([
            'nombre' => 'Servicio oculto',
            'slug' => 'servicio-oculto',
            'activo' => false,
        ]);
        $approvedReview = $activeService->reviews()->create([
            'nombre' => 'Cliente',
            'email' => 'private@example.com',
            'calificacion' => 5,
            'titulo' => 'Excelente',
            'comentario' => 'Muy buena experiencia.',
            'aprobada' => true,
        ]);
        $activeService->reviews()->create([
            'nombre' => 'Pendiente',
            'email' => 'pending@example.com',
            'calificacion' => 4,
            'comentario' => 'Aún no aprobada.',
        ]);
        $inactiveService->reviews()->create([
            'nombre' => 'Otro cliente',
            'email' => 'inactive@example.com',
            'calificacion' => 4,
            'comentario' => 'El servicio está oculto.',
            'aprobada' => true,
        ]);

        $this->getJson('/api/resenas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approvedReview->id)
            ->assertJsonPath('data.0.service.slug', 'landing-page')
            ->assertJsonPath('data.0.calificacion', 5)
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('status', 'success');
    }

    public function test_public_review_feed_supports_stable_pagination(): void
    {
        $service = Servicio::create([
            'nombre' => 'Landing page',
            'slug' => 'landing-page',
            'activo' => true,
        ]);
        $reviewIds = [];
        for ($index = 1; $index <= 8; $index++) {
            $reviewIds[] = $service->reviews()->create([
                'nombre' => "Cliente {$index}",
                'email' => "cliente{$index}@example.com",
                'calificacion' => 5,
                'comentario' => "Reseña {$index}.",
                'aprobada' => true,
            ])->id;
        }

        $this->getJson('/api/resenas?offset=0&limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $reviewIds[7])
            ->assertJsonPath('data.2.id', $reviewIds[5])
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonPath('meta.total', 8);

        $this->getJson('/api/resenas?offset=3&limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $reviewIds[4])
            ->assertJsonPath('data.2.id', $reviewIds[2]);

        $this->getJson('/api/resenas?offset=-1')->assertUnprocessable();
        $this->getJson('/api/resenas?limit=51')->assertUnprocessable();
    }

    public function test_public_review_feed_uses_the_last_successful_cache_when_database_query_fails(): void
    {
        $cache = Cache::store('file');
        $cache->forget('public-service-reviews:last-successful');
        $cache->forget('public-service-reviews:database-outage');
        $service = Servicio::create([
            'nombre' => 'Landing page',
            'slug' => 'landing-page',
            'activo' => true,
        ]);
        $service->reviews()->create([
            'nombre' => 'Cliente',
            'email' => 'private@example.com',
            'calificacion' => 5,
            'comentario' => 'Reseña aprobada.',
            'aprobada' => true,
        ]);

        $this->getJson('/api/resenas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('status', 'success');

        Schema::drop('service_reviews');

        $this->getJson('/api/resenas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Cliente')
            ->assertJsonPath('status', 'degraded');

        $cache->forget('public-service-reviews:last-successful');
        $cache->forget('public-service-reviews:database-outage');
    }

    public function test_service_details_return_slug_taxonomy_and_only_approved_reviews_without_private_email(): void
    {
        $category = ServiceCategory::create([
            'nombre' => 'Páginas web',
            'slug' => 'paginas-web',
            'activo' => true,
            'orden' => 1,
        ]);
        $service = Servicio::create([
            'nombre' => 'Landing page',
            'descripcion' => 'Una página de campaña.',
            'descripcion_larga' => 'Detalle completo de la landing page.',
            'incluye' => ['Diseño adaptable', 'Formulario de contacto'],
            'slug' => 'landing-page',
            'etiqueta' => 'Nuevo',
            'category_id' => $category->id,
            'activo' => true,
            'orden' => 1,
        ]);

        $this->postJson("/api/servicios/{$service->id}/resenas", [
            'nombre' => 'Cliente',
            'email' => 'private@example.com',
            'calificacion' => 5,
            'titulo' => 'Excelente',
            'comentario' => 'Muy buena experiencia.',
        ])->assertCreated()->assertJsonPath('mensaje', 'Reseña recibida y pendiente de moderación.');

        $this->getJson('/api/servicios/landing-page')
            ->assertOk()
            ->assertJsonPath('slug', 'landing-page')
            ->assertJsonPath('etiqueta', 'Nuevo')
            ->assertJsonPath('category.nombre', 'Páginas web')
            ->assertJsonCount(0, 'resenas');

        $admin = User::factory()->create(['is_admin' => true]);
        $reviewId = $this->actingAs($admin)->getJson('/api/admin/resenas-servicios')
            ->assertOk()
            ->assertJsonCount(1)
            ->json('0.id');

        $this->patchJson("/api/admin/resenas-servicios/{$reviewId}", ['aprobada' => true])->assertOk();
        $this->getJson("/api/servicios/{$service->id}/resenas")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.calificacion', 5)
            ->assertJsonMissingPath('0.email');

        $this->getJson('/api/servicios/landing-page')
            ->assertOk()
            ->assertJsonPath('calificacion_promedio', 5)
            ->assertJsonPath('total_resenas', 1);
    }

    public function test_category_and_subcategory_management_is_admin_only_and_service_assignment_is_validated(): void
    {
        $this->postJson('/api/admin/categorias-servicios', [
            'nombre' => 'Web',
        ])->assertUnauthorized();

        $admin = User::factory()->create(['is_admin' => true]);
        $categoryId = $this->actingAs($admin)->postJson('/api/admin/categorias-servicios', [
            'nombre' => 'Páginas web',
        ])->assertCreated()->json('id');
        $subcategoryId = $this->actingAs($admin)->postJson('/api/admin/subcategorias-servicios', [
            'category_id' => $categoryId,
            'nombre' => 'Landing page',
        ])->assertCreated()->json('id');

        $serviceId = $this->actingAs($admin)->postJson('/api/admin/servicios', [
            'nombre' => 'Landing personalizada',
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'etiqueta' => 'Destacado',
        ])->assertCreated()->assertJsonPath('etiqueta', 'Destacado')->json('id');

        $this->getJson('/api/categorias-servicios')
            ->assertOk()
            ->assertJsonPath('0.subcategories.0.nombre', 'Landing page');

        $this->putJson("/api/admin/servicios/{$serviceId}", [
            'etiqueta' => 'Etiqueta no permitida',
        ])->assertUnprocessable();
    }
}
