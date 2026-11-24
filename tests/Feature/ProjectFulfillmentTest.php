<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProjectBrief;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_service_fulfillment_and_client_only_sees_public_delivery_details(): void
    {
        [$clientUser, $project] = $this->createProject();
        $admin = User::factory()->create(['is_admin' => true]);
        ProjectPayment::create([
            'reference' => (string) Str::uuid(),
            'project_id' => $project->id,
            'method' => 'bank_transfer',
            'amount' => 100,
            'currency' => 'USD',
            'status' => 'pagado',
            'paid_at' => now(),
        ]);
        ProjectBrief::create([
            'project_id' => $project->id,
            'answers' => ['objetivo' => 'Vender en línea'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/projects/{$project->id}", [
                'estado' => 'en_progreso',
                'progreso' => 20,
                'recepcion_checklist' => [
                    'alcance_confirmado' => true,
                    'brief_revisado' => true,
                    'insumos_recibidos' => true,
                    'accesos_coordinados' => true,
                ],
                'notas_preparacion' => 'Nota interna: revisar integración de dominio.',
            ])
            ->assertOk()
            ->assertJsonPath('recepcion_checklist.accesos_coordinados', true)
            ->assertJsonPath('notas_preparacion', 'Nota interna: revisar integración de dominio.')
            ->assertJsonPath('payments.0.currency', 'USD');

        $this->getJson('/api/admin/projects')
            ->assertOk()
            ->assertJsonPath('0.brief.answers.objetivo', 'Vender en línea')
            ->assertJsonPath('0.payments.0.currency', 'USD');

        $this->patchJson("/api/admin/projects/{$project->id}", [
            'estado' => 'revision',
            'progreso' => 90,
            'modalidad_entrega' => 'gestionada',
            'url_entregable' => 'https://staging.digitalpyme.test/proyecto',
            'resumen_entrega' => 'Tienda configurada y lista para revisión del cliente.',
            'notas_preparacion' => 'No mostrar esta nota al cliente.',
        ])->assertOk()
            ->assertJsonPath('estado', 'revision')
            ->assertJsonPath('modalidad_entrega', 'gestionada');

        $this->actingAs($clientUser)
            ->getJson("/api/client/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('estado', 'revision')
            ->assertJsonPath('url_entregable', 'https://staging.digitalpyme.test/proyecto')
            ->assertJsonPath('resumen_entrega', 'Tienda configurada y lista para revisión del cliente.')
            ->assertJsonMissingPath('notas_preparacion')
            ->assertJsonMissingPath('recepcion_checklist');

        $this->postJson("/api/client/projects/{$project->id}/delivery-response", [
            'action' => 'approve',
        ])->assertOk()
            ->assertJsonPath('estado', 'completado')
            ->assertJsonMissingPath('notas_preparacion');

        $this->assertNotNull($project->refresh()->entregado_at);
    }

    public function test_service_cannot_start_without_paid_payment_and_completed_intake_checklist(): void
    {
        [, $project] = $this->createProject();
        $admin = User::factory()->create(['is_admin' => true]);
        $checklist = [
            'alcance_confirmado' => true,
            'brief_revisado' => true,
            'insumos_recibidos' => true,
            'accesos_coordinados' => true,
        ];

        $this->actingAs($admin)->postJson('/api/admin/projects', [
            'client_id' => $project->client_id,
            'nombre' => 'Proyecto sin recepción',
            'estado' => 'completado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/projects/{$project->id}", [
                'estado' => 'en_progreso',
                'progreso' => 10,
                'recepcion_checklist' => $checklist,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        ProjectPayment::create([
            'reference' => (string) Str::uuid(),
            'project_id' => $project->id,
            'method' => 'bank_transfer',
            'amount' => 100,
            'currency' => 'PEN',
            'status' => 'pagado',
            'paid_at' => now(),
        ]);

        $this->patchJson("/api/admin/projects/{$project->id}", [
            'estado' => 'en_progreso',
            'progreso' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'recepcion_checklist.alcance_confirmado',
                'recepcion_checklist.brief_revisado',
                'recepcion_checklist.insumos_recibidos',
                'recepcion_checklist.accesos_coordinados',
            ]);

        $this->patchJson("/api/admin/projects/{$project->id}", [
            'estado' => 'en_progreso',
            'progreso' => 10,
            'recepcion_checklist' => $checklist,
        ])->assertOk();

        $this->patchJson("/api/admin/projects/{$project->id}", [
            'estado' => 'revision',
            'progreso' => 90,
            'modalidad_entrega' => 'infra_cliente',
            'url_entregable' => 'http://example.test/proyecto',
            'resumen_entrega' => 'Entrega de prueba.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['url_entregable']);

        $this->patchJson("/api/admin/projects/{$project->id}", [
            'estado' => 'revision',
            'progreso' => 90,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'modalidad_entrega',
                'resumen_entrega',
                'url_entregable',
            ]);
    }

    private function createProject(): array
    {
        $user = User::factory()->create(['is_client' => true]);
        $client = Client::create([
            'user_id' => $user->id,
            'nombre' => $user->name,
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'nombre' => 'Tienda para PYME',
        ]);

        return [$user, $project];
    }
}
