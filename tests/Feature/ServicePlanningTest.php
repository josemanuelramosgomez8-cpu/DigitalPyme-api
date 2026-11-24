<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_service_planning_without_exposing_it_in_the_public_catalog(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $planning = [
            'prioridad' => 'alta',
            'estandarizacion' => 'plantilla',
            'estado_preparacion' => 'disponible',
            'tiempo_estimado_dias' => 12,
        ];

        $serviceId = $this->actingAs($admin)
            ->postJson('/api/admin/servicios', [
                'nombre' => 'Servicio planificado',
                'activo' => true,
                'orden' => 1,
                'planificacion' => $planning,
            ])
            ->assertCreated()
            ->assertJsonPath('planificacion.prioridad', 'alta')
            ->assertJsonPath('planificacion.tiempo_estimado_dias', 12)
            ->json('id');

        $this->getJson('/api/admin/servicios')
            ->assertOk()
            ->assertJsonPath('0.planificacion.estandarizacion', 'plantilla');

        $this->putJson("/api/admin/servicios/{$serviceId}", [
            'planificacion' => [
                'prioridad' => 'media',
                'estandarizacion' => 'a_medida',
                'estado_preparacion' => 'en_preparacion',
                'tiempo_estimado_dias' => 18,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('planificacion.prioridad', 'media')
            ->assertJsonPath('planificacion.tiempo_estimado_dias', 18);

        $this->getJson('/api/servicios')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.planificacion');

        $this->getJson('/api/servicios/servicio-planificado')
            ->assertOk()
            ->assertJsonMissingPath('planificacion');
    }

    public function test_service_planning_values_are_validated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->postJson('/api/admin/servicios', [
                'nombre' => 'Servicio con datos inválidos',
                'activo' => true,
                'orden' => 1,
                'planificacion' => [
                    'prioridad' => 'urgente',
                    'tiempo_estimado_dias' => 0,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'planificacion.prioridad',
                'planificacion.tiempo_estimado_dias',
            ]);
    }
}
