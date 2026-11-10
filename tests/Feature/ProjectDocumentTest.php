<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_upload_list_and_download_documents_for_its_project(): void
    {
        Storage::fake('uploads');
        [$clientUser, $project] = $this->clientProject();
        $this->actingAs($clientUser);

        $upload = $this->post("/api/client/projects/{$project->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document'),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('original_name', 'brief.pdf')
            ->assertJsonMissingPath('file_path');

        $document = ProjectDocument::firstOrFail();
        Storage::disk('uploads')->assertExists($document->file_path);

        $this->getJson("/api/client/projects/{$project->id}/documents")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['original_name' => 'brief.pdf'])
            ->assertJsonMissingPath('0.file_path');

        $this->get("/api/client/projects/{$project->id}/documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=brief.pdf');
    }

    public function test_client_cannot_access_documents_for_another_clients_project(): void
    {
        Storage::fake('uploads');
        [$clientUser] = $this->clientProject('one@example.com');
        [, $otherProject] = $this->clientProject('two@example.com');
        $this->actingAs($clientUser);

        $this->getJson("/api/client/projects/{$otherProject->id}/documents")
            ->assertNotFound();

        $this->post("/api/client/projects/{$otherProject->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document'),
        ])->assertNotFound();

        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_client_upload_rejects_unsupported_and_oversized_files(): void
    {
        Storage::fake('uploads');
        [$clientUser, $project] = $this->clientProject();
        $this->actingAs($clientUser);

        $this->postJson("/api/client/projects/{$project->id}/documents", [
            'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->postJson("/api/client/projects/{$project->id}/documents", [
            'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_admin_can_upload_download_and_delete_project_documents(): void
    {
        Storage::fake('uploads');
        $admin = User::factory()->create(['is_admin' => true]);
        [, $project] = $this->clientProject();
        $this->actingAs($admin);

        $document = $this->post("/api/admin/projects/{$project->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', '%PDF-1.4 contract'),
        ])->assertCreated()
            ->assertJsonPath('original_name', 'contract.pdf')
            ->assertJsonMissingPath('file_path');

        $documentId = $document->json('id');

        $this->getJson("/api/admin/projects/{$project->id}/documents")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.file_path');

        $this->get("/api/admin/projects/{$project->id}/documents/{$documentId}/download")
            ->assertOk();

        $path = ProjectDocument::findOrFail($documentId)->file_path;

        $this->deleteJson("/api/admin/projects/{$project->id}/documents/{$documentId}")
            ->assertOk();

        Storage::disk('uploads')->assertMissing($path);
        $this->assertDatabaseMissing('project_documents', ['id' => $documentId]);
    }

    public function test_administrator_cannot_access_a_document_under_the_wrong_project(): void
    {
        Storage::fake('uploads');
        $admin = User::factory()->create(['is_admin' => true]);
        [, $projectA] = $this->clientProject('a@example.com');
        [, $projectB] = $this->clientProject('b@example.com');
        $document = $projectA->documents()->create([
            'original_name' => 'brief.pdf',
            'file_path' => 'projects/1/brief.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);
        $this->actingAs($admin);

        $this->getJson("/api/admin/projects/{$projectB->id}/documents/{$document->id}/download")
            ->assertNotFound();

        $this->deleteJson("/api/admin/projects/{$projectB->id}/documents/{$document->id}")
            ->assertNotFound();
    }

    public function test_non_admin_cannot_upload_documents_through_admin_routes(): void
    {
        Storage::fake('uploads');
        [$clientUser, $project] = $this->clientProject();
        $this->actingAs($clientUser);

        $this->post("/api/admin/projects/{$project->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document'),
        ])->assertForbidden();

        $this->assertDatabaseCount('project_documents', 0);
    }

    private function clientProject(string $email = 'client@example.com'): array
    {
        $lead = Lead::create([
            'nombre' => 'Cliente de prueba',
            'email' => $email,
            'servicio' => 'Página web',
        ]);
        $user = User::factory()->create(['email' => $email, 'is_client' => true]);
        $client = Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'nombre' => 'Proyecto de prueba',
        ]);

        return [$user, $project];
    }
}
