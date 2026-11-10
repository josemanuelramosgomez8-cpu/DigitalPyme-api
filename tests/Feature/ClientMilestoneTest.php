<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientMilestoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_project_budget_and_approve_a_pending_milestone(): void
    {
        [$user, $project] = $this->clientProject('client@example.com');
        $milestone = $project->milestones()->create([
            'titulo' => 'Diseño aprobado',
            'descripcion' => 'Revisar la propuesta visual.',
            'monto' => 900,
            'estado' => 'pendiente_aprobacion',
        ]);
        $quote = $user->client->lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'descripcion' => 'Diseño y desarrollo',
            'monto' => 1800,
            'estado' => 'aceptada',
        ]);
        $project->update(['cotizacion_id' => $quote->id]);

        $login = $this->postJson('/api/client/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $token = $login->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson("/api/client/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('cotizacion.monto', '1800.00')
            ->assertJsonPath('milestones.0.titulo', 'Diseño aprobado')
            ->assertJsonMissingPath('documents.0.file_path');

        $this->withToken($token)
            ->postJson("/api/client/projects/{$project->id}/milestones/{$milestone->id}/approve")
            ->assertOk()
            ->assertJsonPath('estado', 'aprobado')
            ->assertJsonStructure(['approved_at']);

        $this->assertDatabaseHas('project_milestones', [
            'id' => $milestone->id,
            'estado' => 'aprobado',
        ]);
    }

    public function test_client_cannot_approve_another_clients_milestone_or_an_unrequested_milestone(): void
    {
        [$clientA, $projectA] = $this->clientProject('a@example.com');
        [, $projectB] = $this->clientProject('b@example.com');
        $milestoneA = $projectA->milestones()->create([
            'titulo' => 'Hito privado',
            'estado' => 'pendiente_aprobacion',
        ]);
        $milestoneB = $projectB->milestones()->create([
            'titulo' => 'Hito de otro cliente',
            'estado' => 'pendiente_aprobacion',
        ]);
        $token = $this->postJson('/api/client/login', [
            'email' => $clientA->email,
            'password' => 'password',
        ])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson("/api/client/projects/{$projectB->id}/milestones/{$milestoneB->id}/approve")
            ->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/client/projects/{$projectA->id}/milestones/{$milestoneA->id}/approve")
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/client/projects/{$projectA->id}/milestones/{$milestoneA->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hito']);
    }

    public function test_only_admin_can_create_milestones_and_cannot_update_milestones_of_another_project(): void
    {
        [, $project] = $this->clientProject();
        [, $otherProject] = $this->clientProject('other@example.com');

        $this->postJson("/api/admin/projects/{$project->id}/milestones", [
            'titulo' => 'Primer avance',
            'monto' => 500,
        ])->assertUnauthorized();

        $admin = User::factory()->create(['is_admin' => true]);
        $milestone = $this->actingAs($admin)
            ->postJson("/api/admin/projects/{$project->id}/milestones", [
                'titulo' => 'Primer avance',
                'monto' => 500,
                'estado' => 'pendiente_aprobacion',
            ])->assertCreated()
            ->assertJsonPath('estado', 'pendiente_aprobacion')
            ->json('id');

        $this->patchJson("/api/admin/projects/{$otherProject->id}/milestones/{$milestone}", [
            'estado' => 'aprobado',
        ])->assertNotFound();

        $this->patchJson("/api/admin/projects/{$project->id}/milestones/{$milestone}", [
            'estado' => 'aprobado',
        ])->assertOk()
            ->assertJsonPath('estado', 'aprobado');
    }

    public function test_client_can_download_a_payment_receipt_attached_to_its_project(): void
    {
        Storage::fake('uploads');
        [$user, $project] = $this->clientProject('receipt@example.com');
        $path = UploadedFile::fake()
            ->createWithContent('comprobante.pdf', '%PDF-1.4 receipt')
            ->store("projects/{$project->id}", 'uploads');
        $document = ProjectDocument::create([
            'project_id' => $project->id,
            'original_name' => 'comprobante.pdf',
            'tipo' => 'comprobante',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 18,
        ]);

        $token = $this->postJson('/api/client/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson("/api/client/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('documents.0.tipo', 'comprobante')
            ->assertJsonMissingPath('documents.0.file_path');

        $this->withToken($token)
            ->get("/api/client/projects/{$project->id}/documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=comprobante.pdf');
    }

    private function clientProject(string $email = 'client@example.com'): array
    {
        $lead = Lead::create([
            'nombre' => 'Cliente',
            'email' => $email,
            'servicio' => 'Página web',
        ]);
        $user = User::factory()->create([
            'email' => $email,
            'is_client' => true,
        ]);
        $client = Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'nombre' => 'Proyecto privado',
        ]);

        return [$user, $project];
    }
}
