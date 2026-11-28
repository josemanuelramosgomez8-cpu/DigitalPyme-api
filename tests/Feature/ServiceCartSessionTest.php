<?php

namespace Tests\Feature;

use App\Events\CustomerLifecycleNotificationRequested;
use App\Models\ServiceCartSession;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCartSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_service_cart_replaces_names_with_active_catalog_data(): void
    {
        $this->seed();
        $token = (string) Str::uuid();

        $this->putJson("/api/service-cart-sessions/{$token}", [
            'items' => [
                ['slug' => 'paginas-web', 'name' => 'Nombre manipulado', 'quantity' => 2],
            ],
            'currency' => 'PEN',
        ])->assertOk();

        $session = ServiceCartSession::where('token', $token)->firstOrFail();
        $this->assertSame('Páginas web', $session->items[0]['name']);
        $this->assertSame(2, $session->items[0]['quantity']);

        Servicio::where('slug', 'paginas-web')->update(['activo' => false]);

        $this->putJson("/api/service-cart-sessions/{$token}", [
            'items' => [
                ['slug' => 'paginas-web', 'name' => 'Páginas web', 'quantity' => 1],
            ],
            'currency' => 'PEN',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_public_service_cart_sync_keeps_contact_data_only_with_explicit_consent(): void
    {
        $this->seed();
        $token = (string) Str::uuid();
        $snapshot = [
            'items' => [
                ['slug' => 'paginas-web', 'name' => 'Páginas web', 'quantity' => 1],
            ],
            'currency' => 'USD',
        ];

        $this->putJson("/api/service-cart-sessions/{$token}", [
            ...$snapshot,
            'email' => 'cliente@example.test',
            'phone' => '+51912345678',
        ])->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('service_cart_sessions', [
            'token' => $token,
            'currency' => 'USD',
            'email' => null,
            'phone' => null,
            'communications_consented_at' => null,
        ]);

        $this->putJson("/api/service-cart-sessions/{$token}", [
            ...$snapshot,
            'communications_consent' => true,
            'customer_name' => 'Cliente de prueba',
            'email' => 'cliente@example.test',
            'phone' => '+51912345678',
        ])->assertOk();

        $this->assertDatabaseHas('service_cart_sessions', [
            'token' => $token,
            'email' => 'cliente@example.test',
            'phone' => '+51912345678',
        ]);
        $this->assertNotNull(
            ServiceCartSession::where('token', $token)->value('communications_consented_at'),
        );
    }

    public function test_signed_restore_returns_the_original_services_and_currency(): void
    {
        $token = (string) Str::uuid();
        $session = ServiceCartSession::create([
            'token' => $token,
            'items' => [
                ['slug' => 'tiendas-en-linea', 'name' => 'Tiendas en línea', 'quantity' => 2],
            ],
            'currency' => 'USD',
            'status' => 'active',
            'last_activity_at' => now()->subHours(2),
        ]);
        $restorePath = URL::signedRoute(
            'service-carts.restore',
            ['token' => $session->token],
            now()->addDay(),
            false,
        );

        $this->getJson($restorePath)
            ->assertOk()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('items.0.slug', 'tiendas-en-linea')
            ->assertJsonPath('items.0.quantity', 2);

        $this->assertTrue($session->refresh()->last_activity_at->isToday());
    }

    public function test_admin_can_send_only_one_reminder_to_an_abandoned_consented_service_cart(): void
    {
        Event::fake([CustomerLifecycleNotificationRequested::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $session = ServiceCartSession::create([
            'token' => (string) Str::uuid(),
            'items' => [
                ['slug' => 'branding', 'name' => 'Branding', 'quantity' => 1],
            ],
            'currency' => 'PEN',
            'customer_name' => 'Cliente autorizado',
            'email' => 'cliente@example.test',
            'phone' => '+51912345678',
            'communications_consented_at' => now()->subDay(),
            'status' => 'active',
            'last_activity_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/service-cart-sessions')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.currency', 'PEN');

        $this->postJson("/api/admin/service-cart-sessions/{$session->id}/reminder")
            ->assertOk()
            ->assertJsonPath('reminders_sent', 1);

        Event::assertDispatched(
            CustomerLifecycleNotificationRequested::class,
            fn (CustomerLifecycleNotificationRequested $event) => $event->recipientEmail === 'cliente@example.test'
                && $event->eventType === 'service_cart_abandoned_reminder'
                && str_contains($event->actionUrl ?? '', 'service_restore='),
        );

        $this->postJson("/api/admin/service-cart-sessions/{$session->id}/reminder")
            ->assertUnprocessable();
        $this->assertSame(1, $session->refresh()->reminders_sent);
    }
}
