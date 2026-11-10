<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_visitors_can_submit_a_lead(): void
    {
        $response = $this->postJson('/api/leads', [
            'nombre' => 'Cliente de prueba',
            'email' => 'cliente@example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leads', ['email' => 'cliente@example.com']);
    }

    public function test_public_lead_submissions_are_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/leads', [])->assertUnprocessable();
        }

        $this->postJson('/api/leads', [])->assertTooManyRequests();
    }

    public function test_regular_users_cannot_access_admin_routes_or_log_in_to_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/leads')
            ->assertForbidden();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJson(['mensaje' => 'Correo o contraseña incorrectos']);
    }

    public function test_admin_users_can_access_the_admin_routes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Lead::create([
            'nombre' => 'Cliente existente',
            'email' => 'lead@example.com',
            'servicio' => 'Página web',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_dashboard_returns_operational_metrics_and_rejects_non_admins(): void
    {
        $lead = Lead::create([
            'nombre' => 'Nueva solicitud',
            'email' => 'dashboard@example.com',
            'servicio' => 'Página web',
            'estado' => 'nuevo',
        ]);
        $lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'monto' => 1800,
            'estado' => 'borrador',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('leads_total', 1)
            ->assertJsonPath('leads_nuevos', 1)
            ->assertJsonPath('cotizaciones_total', 1)
            ->assertJsonPath('cotizaciones_pendientes', 1)
            ->assertJsonPath('proyectos_total', 0)
            ->assertJsonPath('pagos_por_verificar', 0)
            ->assertJsonPath('ingresos_confirmados', 0)
            ->assertJsonPath('reservas_del_mes', 0)
            ->assertJsonPath('cupos_proyecto', null);
    }

    public function test_admin_users_can_log_in_and_receive_an_api_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('usuario.email', $admin->email)
            ->assertJsonStructure(['token', 'usuario' => ['name', 'email']]);
    }

    public function test_api_tokens_expire_after_the_configured_lifetime(): void
    {
        config(['sanctum.expiration' => 60]);
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->createToken('test')->plainTextToken;
        $admin->tokens()->firstOrFail()
            ->forceFill(['created_at' => now()->subMinutes(61)])
            ->save();

        $this->withToken($token)->getJson('/api/leads')->assertUnauthorized();
    }

    public function test_admin_password_command_promotes_account_rotates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('old-token');

        $this->artisan('admin:password', ['email' => $user->email])
            ->expectsQuestion('Administrator name', 'Test Administrator')
            ->expectsQuestion('New password (at least 12 characters)', 'A-strong-test-password-123')
            ->expectsQuestion('Confirm new password', 'A-strong-test-password-123')
            ->expectsOutput('Administrator account saved. The password was not displayed.')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('A-strong-test-password-123', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_visitors_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/leads')->assertUnauthorized();
    }
}
