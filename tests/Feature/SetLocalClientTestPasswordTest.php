<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetLocalClientTestPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_test_password_updates_only_client_portal_accounts_and_revokes_tokens(): void
    {
        config(['app.env' => 'local']);

        $firstClient = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $firstClient->id, 'nombre' => $firstClient->name]);
        $firstClient->createToken('test');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $secondClient = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $secondClient->id, 'nombre' => $secondClient->name]);

        $orphanClient = User::factory()->create(['is_client' => true, 'password' => 'orphan-original-password']);
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'admin-original-password']);

        $this->artisan('client:password:local-test')
            ->expectsOutput('Updated 2 local client portal account(s).')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('password', $firstClient->fresh()->password));
        $this->assertTrue(Hash::check('password', $secondClient->fresh()->password));
        $this->assertFalse(Hash::check('password', $orphanClient->fresh()->password));
        $this->assertFalse(Hash::check('password', $admin->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/client/login', [
            'email' => $firstClient->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_local_test_password_command_refuses_non_local_environments(): void
    {
        config(['app.env' => 'production']);

        $client = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $client->id, 'nombre' => $client->name]);
        $originalPassword = $client->password;

        $this->artisan('client:password:local-test')
            ->expectsOutput('This command is only available when APP_ENV=local.')
            ->assertExitCode(1);

        $this->assertSame($originalPassword, $client->fresh()->password);
    }
}
