<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ServiceInventory;
use App\Models\Servicio;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\User;
use App\Notifications\ServiceInventoryAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ServiceInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_service_capacity_and_receives_alert_when_threshold_is_crossed(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $service = Servicio::create([
            'nombre' => 'Tiendas en línea',
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/inventario/servicios')
            ->assertOk()
            ->assertJsonPath('0.configured', false)
            ->assertJsonPath('0.status', 'sin_configurar');

        $this->putJson("/api/admin/inventario/servicios/servicio/{$service->id}/umbral", [
            'minimum' => 2,
        ])->assertOk()
            ->assertJsonPath('minimum', 2)
            ->assertJsonPath('status', 'agotado');

        $this->postJson("/api/admin/inventario/servicios/servicio/{$service->id}/movimientos", [
            'tipo' => 'entrada',
            'cantidad' => 5,
            'motivo' => 'Apertura de cupos del mes',
        ])->assertCreated()
            ->assertJsonPath('available', 5)
            ->assertJsonPath('status', 'disponible');

        $this->postJson("/api/admin/inventario/servicios/servicio/{$service->id}/movimientos", [
            'tipo' => 'salida',
            'cantidad' => 3,
            'motivo' => 'Asignación parcial',
        ])->assertCreated()
            ->assertJsonPath('available', 2)
            ->assertJsonPath('status', 'cupo_bajo');

        Notification::assertSentTo($admin, ServiceInventoryAlert::class);
        $this->assertDatabaseHas('service_inventory_movements', [
            'service_inventory_id' => ServiceInventory::where('servicio_id', $service->id)->value('id'),
            'tipo' => 'salida',
            'cantidad' => 3,
            'motivo' => 'Asignación parcial',
            'created_by' => $admin->id,
        ]);

        $this->postJson("/api/admin/inventario/servicios/servicio/{$service->id}/movimientos", [
            'tipo' => 'salida',
            'cantidad' => 3,
            'motivo' => 'Sobreasignación',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['cantidad']);

        $this->assertDatabaseHas('service_inventories', [
            'servicio_id' => $service->id,
            'available' => 2,
            'minimum' => 2,
        ]);
    }

    public function test_service_stock_movements_can_be_linked_to_a_project_and_its_client(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $clientUser = User::factory()->create();
        $client = Client::create([
            'user_id' => $clientUser->id,
            'nombre' => 'Cliente de prueba',
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'nombre' => 'Implementación web',
        ]);
        $service = Servicio::create([
            'nombre' => 'Páginas corporativas',
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/inventario/servicios/servicio/{$service->id}/movimientos", [
                'tipo' => 'entrada',
                'cantidad' => 4,
                'motivo' => 'Cupos habilitados',
            ])->assertCreated();

        $this->postJson("/api/admin/inventario/servicios/servicio/{$service->id}/movimientos", [
            'tipo' => 'salida',
            'cantidad' => 1,
            'motivo' => 'Reserva del servicio',
            'project_id' => $project->id,
        ])->assertCreated()
            ->assertJsonPath('movement.project.id', $project->id)
            ->assertJsonPath('movement.client.id', $client->id);

        $this->assertDatabaseHas('service_inventory_movements', [
            'project_id' => $project->id,
            'client_id' => $client->id,
            'tipo' => 'salida',
        ]);
    }

    public function test_a_service_subcategory_has_its_own_independent_capacity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = ServiceCategory::create([
            'nombre' => 'Páginas web',
            'slug' => 'paginas-web',
            'activo' => true,
            'orden' => 1,
        ]);
        $subcategory = ServiceSubcategory::create([
            'category_id' => $category->id,
            'nombre' => 'Landing page',
            'slug' => 'landing-page',
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/inventario/servicios')
            ->assertOk()
            ->assertJsonPath('0.resource_type', 'subcategoria')
            ->assertJsonPath('0.name', 'Landing page')
            ->assertJsonPath('0.category', 'Páginas web');

        $this->putJson("/api/admin/inventario/servicios/subcategoria/{$subcategory->id}/umbral", [
            'minimum' => 1,
        ])->assertOk()
            ->assertJsonPath('resource_type', 'subcategoria')
            ->assertJsonPath('resource_id', $subcategory->id);

        $this->postJson("/api/admin/inventario/servicios/subcategoria/{$subcategory->id}/movimientos", [
            'tipo' => 'entrada',
            'cantidad' => 3,
            'motivo' => 'Bloques de landing disponibles',
        ])->assertCreated()
            ->assertJsonPath('available', 3);

        $this->assertDatabaseHas('service_inventories', [
            'servicio_id' => null,
            'subcategory_id' => $subcategory->id,
            'available' => 3,
            'minimum' => 1,
        ]);
    }

    public function test_only_administrators_can_read_or_change_service_inventory(): void
    {
        $service = Servicio::create([
            'nombre' => 'Landing Pages',
            'activo' => true,
            'orden' => 1,
        ]);

        $this->getJson('/api/admin/inventario/servicios')->assertUnauthorized();
        $this->putJson("/api/admin/inventario/servicios/servicio/{$service->id}/umbral", [
            'minimum' => 1,
        ])->assertUnauthorized();
    }
}
