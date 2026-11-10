<?php

namespace Tests\Feature;

use App\Mail\ClientRegisteredNotification;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_clients_and_view_their_request_history(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->createClient();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $client->lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'monto' => 1500,
            'estado' => 'enviada',
        ]);
        Lead::create([
            'nombre' => 'Cliente de prueba',
            'email' => 'client@example.com',
            'telefono' => '999123456',
            'servicio' => 'Marketing digital',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/clients?search=999123456')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user.email', 'client@example.com');

        $this->actingAs($admin)
            ->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->assertJsonCount(2, 'requests')
            ->assertJsonFragment(['servicio' => 'Página web']);
    }

    public function test_visitor_can_register_as_a_client_and_admin_is_notified_without_receiving_password(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $response = $this->postJson('/api/client/register', [
            'nombre' => 'Nueva clienta',
            'email' => 'new-client@example.com',
            'password' => 'Client-password-2026',
            'password_confirmation' => 'Client-password-2026',
        ]);

        $response->assertCreated()
            ->assertJsonPath('usuario.email', 'new-client@example.com')
            ->assertJsonPath('usuario.must_change_password', false)
            ->assertJsonPath('notificacion_admin_enviada', true)
            ->assertJsonStructure(['token', 'usuario' => ['name', 'email']]);

        $client = Client::whereHas('user', fn ($query) => $query->where('email', 'new-client@example.com'))->firstOrFail();
        $this->assertTrue($client->user->is_client);
        $this->assertTrue(Hash::check('Client-password-2026', $client->user->password));
        $this->assertNull($client->lead_id);

        Mail::assertSent(ClientRegisteredNotification::class, function (ClientRegisteredNotification $mail) use ($admin): bool {
            $body = $mail->render();

            return $mail->hasTo($admin->email)
                && str_contains($body, 'new-client@example.com')
                && ! str_contains($body, 'Client-password-2026');
        });
    }

    public function test_client_registration_rejects_duplicate_email_and_short_password(): void
    {
        $this->createClient();

        $this->postJson('/api/client/register', [
            'nombre' => 'Cliente duplicado',
            'email' => 'client@example.com',
            'password' => 'Client-password-2026',
            'password_confirmation' => 'Client-password-2026',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/client/register', [
            'nombre' => 'Nuevo cliente',
            'email' => 'new-client@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_admin_can_update_client_contact_data_and_account_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->createClient();

        $this->actingAs($admin)
            ->patchJson("/api/clients/{$client->id}", [
                'nombre' => 'Cliente actualizado',
                'empresa' => 'Empresa nueva',
                'email' => 'updated@example.com',
                'telefono' => '999000111',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Cliente actualizado')
            ->assertJsonPath('lead.telefono', '999000111');

        $this->actingAs($admin)
            ->patchJson("/api/clients/{$client->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/client/login', [
            'email' => 'updated@example.com',
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_admin_can_set_a_temporary_password_and_force_a_change(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->createClient();

        $this->actingAs($admin)
            ->postJson("/api/clients/{$client->id}/password", [
                'password' => 'Temporary-password-2026',
                'password_confirmation' => 'Temporary-password-2026',
                'force_change' => true,
            ])
            ->assertOk();

        $this->assertTrue($client->user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Temporary-password-2026', $client->user->fresh()->password));
    }

    public function test_client_must_change_a_temporary_password_before_portal_access(): void
    {
        $client = $this->createClient();
        $client->user->forceFill([
            'password' => 'Temporary-password-2026',
            'must_change_password' => true,
        ])->save();

        $login = $this->postJson('/api/client/login', [
            'email' => 'client@example.com',
            'password' => 'Temporary-password-2026',
        ])->assertOk()
            ->assertJsonPath('usuario.must_change_password', true);

        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/client/projects')->assertStatus(423);

        $this->withToken($token)->putJson('/api/client/password', [
            'current_password' => 'Temporary-password-2026',
            'password' => 'My-new-password-2026',
            'password_confirmation' => 'My-new-password-2026',
        ])->assertOk();

        $this->assertFalse($client->user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('My-new-password-2026', $client->user->fresh()->password));
    }

    public function test_admin_can_email_a_standard_reset_link_to_an_active_client(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->createClient();

        $this->actingAs($admin)
            ->postJson("/api/clients/{$client->id}/password/reset-link")
            ->assertOk()
            ->assertJsonPath('email_enviado', true);

        Notification::assertSentTo($client->user, ResetPassword::class);
    }

    public function test_client_can_use_a_reset_link_and_the_token_cannot_be_reused(): void
    {
        $client = $this->createClient();
        $client->user->forceFill([
            'must_change_password' => true,
        ])->save();
        $token = Password::createToken($client->user);

        $payload = [
            'email' => 'client@example.com',
            'token' => $token,
            'password' => 'My-reset-password-2026',
            'password_confirmation' => 'My-reset-password-2026',
        ];

        $this->postJson('/api/password/reset', $payload)
            ->assertOk()
            ->assertJsonPath('mensaje', 'Contraseña restablecida. Ya puedes iniciar sesión.');

        $this->assertFalse($client->user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($payload['password'], $client->user->fresh()->password));

        $this->postJson('/api/password/reset', $payload)->assertUnprocessable();
    }

    public function test_only_admins_can_manage_clients(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/clients')
            ->assertForbidden();
    }

    public function test_unauthenticated_users_cannot_manage_clients(): void
    {
        $client = $this->createClient();
        $this->getJson("/api/clients/{$client->id}")
            ->assertUnauthorized();
    }

    private function createClient(): Client
    {
        $lead = Lead::create([
            'nombre' => 'Cliente de prueba',
            'email' => 'client@example.com',
            'telefono' => '999123456',
            'servicio' => 'Página web',
        ]);
        $user = User::factory()->create([
            'name' => 'Cliente de prueba',
            'email' => 'client@example.com',
            'is_client' => true,
        ]);

        return Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => 'Cliente de prueba',
        ]);
    }
}
