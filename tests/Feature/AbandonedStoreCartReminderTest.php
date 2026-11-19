<?php

namespace Tests\Feature;

use App\Events\CustomerLifecycleNotificationRequested;
use App\Models\Client;
use App\Models\StoreCart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AbandonedStoreCartReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_one_reminder_per_due_stage_only_with_consent(): void
    {
        $user = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $user->id, 'nombre' => $user->name]);

        $cart = StoreCart::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'items' => [['product_id' => 1, 'cantidad' => 2]],
            'currency' => 'PEN',
            'last_activity_at' => now()->subHour(),
            'communications_consented_at' => now()->subDays(2),
        ]);
        StoreCart::create([
            'user_id' => User::factory()->create()->id,
            'email' => 'sin-consentimiento@example.test',
            'items' => [['product_id' => 1, 'cantidad' => 1]],
            'currency' => 'PEN',
            'last_activity_at' => now()->subDays(5),
        ]);

        Event::fake([CustomerLifecycleNotificationRequested::class]);

        Artisan::call('carts:remind');
        Artisan::call('carts:remind');

        $this->assertDatabaseHas('store_carts', [
            'id' => $cart->id,
            'reminders_sent' => 1,
        ]);
        Event::assertDispatchedTimes(CustomerLifecycleNotificationRequested::class, 1);

        $cart->refresh()->update(['last_activity_at' => now()->subHours(24)]);
        Artisan::call('carts:remind');
        $this->assertDatabaseHas('store_carts', [
            'id' => $cart->id,
            'reminders_sent' => 2,
        ]);
        Event::assertDispatchedTimes(CustomerLifecycleNotificationRequested::class, 2);

        $cart->refresh()->update(['last_activity_at' => now()->subHours(72)]);
        Artisan::call('carts:remind');
        $cart->refresh();
        $this->assertSame(3, $cart->reminders_sent);
        Event::assertDispatchedTimes(CustomerLifecycleNotificationRequested::class, 3);

        Artisan::call('carts:remind');
        Event::assertDispatchedTimes(CustomerLifecycleNotificationRequested::class, 3);
    }
}
