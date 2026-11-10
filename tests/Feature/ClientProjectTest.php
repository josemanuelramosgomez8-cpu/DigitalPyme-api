<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_convert_a_lead_into_a_client_account(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        $response = $this->postJson("/api/leads/{$lead->id}/client");

        $response->assertCreated()
            ->assertJsonPath('lead_id', $lead->id)
            ->assertJsonPath('user.email', $lead->email);

        $this->assertDatabaseHas('clients', [
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => $lead->email,
            'is_client' => true,
        ]);
    }

    public function test_admin_can_supply_an_email_when_converting_a_lead_without_one(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['email' => null, 'telefono' => '999111222']);

        $this->postJson("/api/leads/{$lead->id}/client", ['email' => 'client@example.com'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'client@example.com');

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'email' => 'client@example.com',
        ]);
    }

    public function test_lead_cannot_be_converted_without_an_email_or_twice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['email' => null, 'telefono' => '999111222']);

        $this->postJson("/api/leads/{$lead->id}/client")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson("/api/leads/{$lead->id}/client", ['email' => 'client@example.com'])
            ->assertCreated();

        $this->postJson("/api/leads/{$lead->id}/client")
            ->assertStatus(409);
    }

    public function test_accepting_a_quote_creates_one_client_and_project(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();
        $quote = $lead->cotizaciones()->create([
            'servicio' => 'Tienda en línea',
            'descripcion' => 'Diseño y desarrollo de tienda',
            'monto' => 4200,
        ]);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertOk()
            ->assertJsonPath('estado', 'aceptada');

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'ganado']);
        $this->assertDatabaseHas('clients', ['lead_id' => $lead->id]);
        $this->assertDatabaseHas('projects', [
            'cotizacion_id' => $quote->id,
            'nombre' => 'Tienda en línea',
            'estado' => 'planificacion',
            'progreso' => 0,
        ]);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertOk();

        $this->assertDatabaseCount('projects', 1);
    }

    public function test_quote_without_a_client_email_cannot_be_accepted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['email' => null, 'telefono' => '999111222']);
        $quote = $lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'descripcion' => 'Diseño y desarrollo del sitio web.',
            'monto' => 1800,
        ]);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertDatabaseHas('cotizaciones', ['id' => $quote->id, 'estado' => 'borrador']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'nuevo']);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_client_can_only_list_and_view_its_own_projects(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $leadA = $this->createLead(['email' => 'a@example.com']);
        $leadB = $this->createLead(['email' => 'b@example.com']);
        $this->postJson("/api/leads/{$leadA->id}/client")->assertCreated();
        $this->postJson("/api/leads/{$leadB->id}/client")->assertCreated();
        $clientA = Client::where('lead_id', $leadA->id)->firstOrFail();
        $clientB = Client::where('lead_id', $leadB->id)->firstOrFail();
        $projectA = Project::create([
            'client_id' => $clientA->id,
            'nombre' => 'Proyecto privado A',
        ]);
        $projectB = Project::create([
            'client_id' => $clientB->id,
            'nombre' => 'Proyecto privado B',
        ]);

        $this->artisan('client:password', ['email' => $leadA->email])
            ->expectsQuestion('New password (at least 12 characters)', 'Client-password-123')
            ->expectsQuestion('Confirm new password', 'Client-password-123')
            ->expectsOutput('Client password updated. The password was not displayed.')
            ->assertExitCode(0);

        $login = $this->postJson('/api/client/login', [
            'email' => $leadA->email,
            'password' => 'Client-password-123',
        ])->assertOk();
        $token = $login->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/client/projects')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['nombre' => 'Proyecto privado A'])
            ->assertJsonMissing(['nombre' => 'Proyecto privado B']);

        $this->withToken($token)
            ->getJson("/api/client/projects/{$projectA->id}")
            ->assertOk()
            ->assertJsonPath('nombre', 'Proyecto privado A');

        $this->withToken($token)
            ->getJson("/api/client/projects/{$projectB->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->putJson('/api/client/password', [
                'current_password' => 'Client-password-123',
                'password' => 'Changed-client-password-456',
                'password_confirmation' => 'Changed-client-password-456',
            ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/client/projects')
            ->assertUnauthorized();

        $this->postJson('/api/client/login', [
            'email' => $leadA->email,
            'password' => 'Changed-client-password-456',
        ])->assertOk();
    }

    public function test_admin_can_create_and_update_a_project(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();
        $conversion = $this->postJson("/api/leads/{$lead->id}/client")->assertCreated();
        $clientId = $conversion->json('id');

        $project = $this->postJson('/api/admin/projects', [
            'client_id' => $clientId,
            'nombre' => 'Proyecto manual',
            'fecha_inicio' => '2026-10-20',
            'fecha_entrega' => '2026-11-20',
        ])->assertCreated()
            ->assertJsonPath('estado', 'planificacion')
            ->assertJsonPath('progreso', 0);

        $this->patchJson("/api/admin/projects/{$project->json('id')}", [
            'estado' => 'en_progreso',
            'progreso' => 30,
        ])->assertOk()
            ->assertJsonPath('estado', 'en_progreso')
            ->assertJsonPath('progreso', 30);
    }

    private function createLead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'nombre' => 'Cliente de prueba',
            'empresa' => 'Empresa de prueba',
            'email' => 'cliente@example.com',
            'servicio' => 'Página web',
        ], $attributes));
    }
}
