<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_submit_a_request_only_for_its_project(): void
    {
        [$clientUser, $project] = $this->createProject();
        [, $otherProject] = $this->createProject();

        $this->actingAs($clientUser)
            ->postJson("/api/client/projects/{$project->id}/service-requests", [
                'type' => 'reprogramacion',
                'reason' => 'Necesito coordinar una nueva fecha para la entrega.',
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'reprogramacion')
            ->assertJsonPath('status', 'pendiente')
            ->assertJsonMissingPath('internal_notes');

        $this->postJson("/api/client/projects/{$otherProject->id}/service-requests", [
            'type' => 'cancelacion',
            'reason' => 'Solicito cancelar este proyecto.',
        ])->assertNotFound();

        $this->assertDatabaseCount('project_service_requests', 1);
        $this->assertSame('planificacion', $project->refresh()->estado);
    }

    public function test_admin_must_record_amount_and_currency_for_a_refund_and_client_never_sees_internal_notes(): void
    {
        [$clientUser, $project] = $this->createProject();
        $admin = User::factory()->create(['is_admin' => true]);
        $serviceRequest = $project->serviceRequests()->create([
            'type' => 'reembolso',
            'reason' => 'Solicito revisar el reembolso de mi proyecto.',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/project-service-requests/{$serviceRequest->id}", [
                'status' => 'resuelta',
                'resolution' => 'reembolso_parcial',
                'response_message' => 'Aprobamos un reembolso parcial.',
                'internal_notes' => 'Revisado por administración.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution_amount']);

        $this->patchJson("/api/admin/project-service-requests/{$serviceRequest->id}", [
            'status' => 'resuelta',
            'resolution' => 'reembolso_parcial',
            'resolution_amount' => 80.5,
            'resolution_currency' => 'USD',
            'response_message' => 'Aprobamos un reembolso parcial.',
            'internal_notes' => 'Revisado por administración.',
        ])
            ->assertOk()
            ->assertJsonPath('resolution_currency', 'USD')
            ->assertJsonPath('internal_notes', 'Revisado por administración.');

        $this->getJson('/api/admin/projects')
            ->assertOk()
            ->assertJsonPath('0.service_requests.0.internal_notes', 'Revisado por administración.');

        $this->actingAs($clientUser)
            ->getJson("/api/client/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('service_requests.0.status', 'resuelta')
            ->assertJsonPath('service_requests.0.resolution_amount', '80.50')
            ->assertJsonPath('service_requests.0.resolution_currency', 'USD')
            ->assertJsonMissingPath('service_requests.0.internal_notes');

        $this->assertSame('planificacion', $project->refresh()->estado);
    }

    public function test_service_request_cannot_be_reopened_after_resolution(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $project] = $this->createProject();
        $serviceRequest = $project->serviceRequests()->create([
            'type' => 'ajuste',
            'reason' => 'Solicito un ajuste menor en la entrega.',
            'status' => 'resuelta',
            'resolution' => 'ajuste_incluido',
            'response_message' => 'El ajuste fue incluido en la revisión.',
            'resolved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/project-service-requests/{$serviceRequest->id}", [
                'status' => 'en_revision',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
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
            'nombre' => 'Proyecto de servicio',
        ]);

        return [$user, $project];
    }
}
