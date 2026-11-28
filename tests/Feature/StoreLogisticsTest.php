<?php

namespace Tests\Feature;

use App\Mail\StoreOrderConfirmation;
use App\Models\Client;
use App\Models\StockMovement;
use App\Models\StoreCoupon;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutIdentity(): array
    {
        return [
            'telefono' => '999888777',
            'tipo_comprobante' => 'boleta',
            'tipo_documento' => 'dni',
            'numero_documento' => '12345678',
            'acepta_politicas' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bank_transfer.account' => '001-123456',
            'services.yape_plin.enabled' => true,
            'services.yape_plin.phone' => '999888777',
        ]);
    }

    public function test_checkout_requires_and_records_legal_policy_acceptance(): void
    {
        $user = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $user->id, 'nombre' => $user->name]);
        $product = StoreProduct::create([
            'sku' => 'LEGAL-CHECKOUT',
            'nombre' => 'Producto con aceptación legal',
            'precio' => 50,
            'stock' => 2,
        ]);
        $payload = [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ];
        unset($payload['acepta_politicas']);

        $this->actingAs($user)
            ->postJson('/api/client/store/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['acepta_politicas']);

        $payload['acepta_politicas'] = true;
        $orderId = $this->actingAs($user)
            ->postJson('/api/client/store/orders', $payload)
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'legal_accepted_ip' => '127.0.0.1',
            'legal_policy_version' => config('services.digitalpyme.legal_policy_version'),
        ]);
        $this->assertNotNull(
            StoreOrder::query()->findOrFail($orderId)->legal_accepted_at,
        );
    }

    public function test_shipping_options_and_free_shipping_threshold_are_configurable_by_admin(): void
    {
        $methods = [
            ['key' => 'recojo', 'nombre' => 'Recojo', 'cost' => 0, 'delivery_days' => 0, 'enabled' => true],
            ['key' => 'estandar', 'nombre' => 'Estándar', 'cost' => 9, 'delivery_days' => 4, 'enabled' => true],
            ['key' => 'express', 'nombre' => 'Express', 'cost' => 18, 'delivery_days' => 1, 'enabled' => true],
            ['key' => 'same_day', 'nombre' => 'Same-day', 'cost' => 25, 'delivery_days' => 0, 'enabled' => false],
        ];
        $this->putJson('/api/admin/site-settings', [
            'coverage' => [
                'shipping_methods' => $methods,
                'free_shipping_threshold' => 250,
            ],
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->putJson('/api/admin/site-settings', [
                'coverage' => [
                    'shipping_methods' => $methods,
                    'free_shipping_threshold' => 250,
                ],
            ])->assertOk()
            ->assertJsonPath('coverage.free_shipping_threshold', 250)
            ->assertJsonPath('coverage.shipping_methods.3.enabled', false);

        $this->getJson('/api/store/shipping-options')
            ->assertOk()
            ->assertJsonPath('free_shipping_threshold', 250)
            ->assertJsonPath('methods.0.cost', 0)
            ->assertJsonPath('methods.1.cost', 9);
    }

    public function test_checkout_uses_zone_fee_as_standard_base_and_adds_only_method_surcharge(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => [
                'shipping_zones' => [
                    ['location' => 'Lima', 'fee' => 8, 'delivery_days' => 3],
                ],
                'shipping_methods' => [
                    ['key' => 'recojo', 'nombre' => 'Recojo', 'cost' => 0, 'delivery_days' => 0, 'enabled' => true],
                    ['key' => 'estandar', 'nombre' => 'Estándar', 'cost' => 12, 'delivery_days' => 3, 'enabled' => true],
                    ['key' => 'express', 'nombre' => 'Express', 'cost' => 20, 'delivery_days' => 1, 'enabled' => true],
                    ['key' => 'same_day', 'nombre' => 'Same-day', 'cost' => 28, 'delivery_days' => 0, 'enabled' => true],
                ],
            ],
        ])->assertOk();

        $product = StoreProduct::create([
            'sku' => 'ZONE-SHIPPING',
            'nombre' => 'Producto para probar tarifa por zona',
            'precio' => 50,
            'stock' => 2,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);

        $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'express',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'lIMA',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('costo_envio', '16.00')
            ->assertJsonPath('igv_amount', '11.88')
            ->assertJsonPath('total', '77.88');
    }

    public function test_shipping_quote_returns_itemized_totals_in_selected_currency_without_reserving_stock(): void
    {
        config(['services.store.usd_exchange_rate' => '3.70']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => [
                'shipping_zones' => [
                    ['location' => 'Lima', 'fee' => 8, 'delivery_days' => 3],
                ],
            ],
        ])->assertOk();
        $product = StoreProduct::create([
            'sku' => 'SHIPPING-QUOTE',
            'nombre' => 'Producto para cotizar envío',
            'precio' => 50,
            'stock' => 2,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $this->actingAs($clientUser)->putJson('/api/client/store/cart', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            'communications_consent' => false,
            'currency' => 'USD',
        ])->assertOk();

        $this->actingAs($clientUser)->postJson('/api/client/store/shipping-quote', [
            'metodo_envio' => 'express',
            'ubicacion_entrega' => 'lima',
        ])->assertOk()
            ->assertJsonPath('subtotal', '50.00')
            ->assertJsonPath('costo_envio', '16.00')
            ->assertJsonPath('igv_amount', '11.88')
            ->assertJsonPath('total', '77.88')
            ->assertJsonPath('payment_currency', 'PEN')
            ->assertJsonPath('display_currency', 'USD')
            ->assertJsonPath('display_amounts.subtotal', '13.51')
            ->assertJsonPath('display_amounts.shipping', '4.32')
            ->assertJsonPath('display_amounts.total', '21.05')
            ->assertJsonPath('shipping.zone_configured', true)
            ->assertJsonPath('shipping.delivery_days', 3)
            ->assertJsonPath('stock_reserved', false);

        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 2]);
    }

    public function test_shipping_quote_uses_configured_method_fee_when_no_zone_matches(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => [
                'shipping_zones' => [
                    ['location' => 'Lima', 'fee' => 8, 'delivery_days' => 3],
                ],
            ],
        ])->assertOk();
        $product = StoreProduct::create([
            'sku' => 'SHIPPING-FALLBACK',
            'nombre' => 'Producto con tarifa general',
            'precio' => 50,
            'stock' => 1,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $this->actingAs($clientUser)->putJson('/api/client/store/cart', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            'communications_consent' => false,
        ])->assertOk();

        $this->actingAs($clientUser)->postJson('/api/client/store/shipping-quote', [
            'metodo_envio' => 'estandar',
            'ubicacion_entrega' => 'Arequipa',
        ])->assertOk()
            ->assertJsonPath('costo_envio', '12.00')
            ->assertJsonPath('shipping.zone_configured', false)
            ->assertJsonPath('shipping.location', 'Arequipa');
    }

    public function test_tumbes_zones_return_verified_demo_coverage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => [
                'shipping_zones' => [
                    ['location' => 'Tumbes', 'fee' => 8, 'delivery_days' => 2],
                    ['location' => 'Corrales', 'fee' => 10, 'delivery_days' => 2],
                    ['location' => 'Zorritos', 'fee' => 15, 'delivery_days' => 3],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=Tumbes')
            ->assertOk()
            ->assertJsonPath('envio.ubicacion', 'Tumbes')
            ->assertJsonPath('envio.cubierto', true)
            ->assertJsonPath('envio.costo', 8)
            ->assertJsonPath('envio.plazo_dias', 2);

        $this->getJson('/api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=Zarumilla')
            ->assertOk()
            ->assertJsonPath('envio.cubierto', false);
    }

    public function test_pickup_shipping_quote_has_no_cost_or_delivery_wait(): void
    {
        $product = StoreProduct::create([
            'sku' => 'SHIPPING-PICKUP',
            'nombre' => 'Producto para recojo',
            'precio' => 50,
            'stock' => 1,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $this->actingAs($clientUser)->putJson('/api/client/store/cart', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            'communications_consent' => false,
        ])->assertOk();

        $this->actingAs($clientUser)->postJson('/api/client/store/shipping-quote', [
            'metodo_envio' => 'recojo',
            'ubicacion_entrega' => 'Lima',
        ])->assertOk()
            ->assertJsonPath('costo_envio', '0.00')
            ->assertJsonPath('shipping.method', 'recojo')
            ->assertJsonPath('shipping.delivery_days', 0)
            ->assertJsonPath('stock_reserved', false);
    }

    public function test_store_only_offers_configured_manual_payment_methods(): void
    {
        config([
            'services.bank_transfer.account' => null,
            'services.bank_transfer.cci' => null,
            'services.yape_plin.enabled' => false,
        ]);

        $this->getJson('/api/store/payment-options')
            ->assertOk()
            ->assertJsonPath('card_enabled', false)
            ->assertJsonPath('card_environment', 'sandbox')
            ->assertJsonPath('bank_transfer.enabled', false)
            ->assertJsonPath('yape_plin.enabled', false);

        $user = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $user->id, 'nombre' => $user->name]);
        $product = StoreProduct::create([
            'sku' => 'PAYMENT-DISABLED',
            'nombre' => 'Producto con pago no configurado',
            'precio' => 10,
            'stock' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'yape_plin',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_store_currency_preference_displays_usd_but_persists_and_charges_pen(): void
    {
        config(['services.store.usd_exchange_rate' => '3.70']);

        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'USD-CURRENCY',
            'nombre' => 'Producto con conversión',
            'precio' => 100,
            'stock' => 2,
        ]);

        $this->actingAs($clientUser)->putJson('/api/client/store/cart', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            'communications_consent' => false,
            'currency' => 'USD',
        ])->assertOk()
            ->assertJsonPath('currency', 'USD');

        $this->getJson('/api/store/payment-options')
            ->assertOk()
            ->assertJsonPath('currencies.1.code', 'USD')
            ->assertJsonPath('currencies.1.enabled', true)
            ->assertJsonPath('currencies.1.exchange_rate', '3.700000');
        $this->getJson('/api/store/products')
            ->assertOk()
            ->assertJsonPath('0.precio', '100.00')
            ->assertJsonPath('0.precio_usd', '27.03');

        $orderId = $this->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('display_currency', 'USD')
            ->assertJsonPath('total', '132.16')
            ->assertJsonPath('display_amounts.currency', 'USD')
            ->assertJsonPath('display_amounts.total', '35.72')
            ->json('id');

        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'total' => '132.16',
            'display_currency' => 'USD',
            'display_exchange_rate' => '3.700000',
        ]);
        $this->assertDatabaseHas('store_carts', [
            'user_id' => $clientUser->id,
            'currency' => 'USD',
        ]);
    }

    public function test_store_currency_selection_requires_a_configured_usd_exchange_rate(): void
    {
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'USD-RATE-REQUIRED',
            'nombre' => 'Producto para validar tipo de cambio',
            'precio' => 10,
            'stock' => 1,
        ]);

        $this->actingAs($clientUser)->putJson('/api/client/store/cart', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            'communications_consent' => false,
            'currency' => 'USD',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $this->getJson('/api/store/payment-options')
            ->assertOk()
            ->assertJsonPath('currencies.1.enabled', false);
    }

    public function test_direct_coordination_checkout_works_without_payment_providers_and_keeps_selected_currency(): void
    {
        Storage::fake('uploads');
        Mail::fake();
        config([
            'services.bank_transfer.account' => null,
            'services.bank_transfer.cci' => null,
            'services.yape_plin.enabled' => false,
            'services.mercadopago.access_token' => null,
            'services.store.usd_exchange_rate' => '3.70',
            'services.digitalpyme.solicitudes_email' => 'ventas@example.test',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'contact' => ['whatsapp_phone' => '+51912345678'],
        ])->assertOk();

        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'DIRECT-COORDINATION',
            'nombre' => 'Producto para coordinar',
            'precio' => 50,
            'stock' => 2,
        ]);

        $this->getJson('/api/store/payment-options')
            ->assertOk()
            ->assertJsonPath('card_enabled', false)
            ->assertJsonPath('bank_transfer.enabled', false)
            ->assertJsonPath('yape_plin.enabled', false)
            ->assertJsonPath('direct_coordination.enabled', true);

        $orderId = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'currency' => 'USD',
            'metodo_envio' => 'estandar',
            'payment_method' => 'direct_coordination',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('payment_method', 'direct_coordination')
            ->assertJsonPath('payment_status', 'pendiente')
            ->assertJsonPath('display_currency', 'USD')
            ->assertJsonPath('display_amounts.currency', 'USD')
            ->assertJsonPath('coordinacion.email', 'ventas@example.test')
            ->assertJsonPath('coordinacion.whatsapp_phone', '+51912345678')
            ->assertJsonPath('coordinacion.total_pen', '73.16')
            ->json('id');

        Mail::assertSent(StoreOrderConfirmation::class, fn (StoreOrderConfirmation $mail) => $mail->order->id === $orderId);

        $this->actingAs($clientUser)->post("/api/client/store/orders/{$orderId}/receipt", [
            'file' => UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'comprobante_enviado');

        $this->actingAs($admin)->patchJson("/api/admin/store/orders/{$orderId}/payment", [
            'payment_status' => 'pagado',
        ])->assertOk();
        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'payment_method' => 'direct_coordination',
            'display_currency' => 'USD',
            'payment_status' => 'pagado',
        ]);
    }

    public function test_admins_receive_inventory_alerts_only_when_stock_enters_a_new_alert_state(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $productId = $this->actingAs($admin)->postJson('/api/admin/store/products', [
            'sku' => 'STOCK-ALERT',
            'nombre' => 'Producto con alerta',
            'precio' => 10,
            'stock' => 10,
            'punto_reorden' => 2,
        ])->assertCreated()->json('id');

        $this->postJson("/api/admin/store/products/{$productId}/movements", [
            'tipo' => 'salida',
            'cantidad' => 8,
            'motivo' => 'Ajuste de prueba',
        ])->assertOk();
        $this->postJson("/api/admin/store/products/{$productId}/movements", [
            'tipo' => 'salida',
            'cantidad' => 1,
            'motivo' => 'Ajuste de prueba',
        ])->assertOk();
        $this->assertDatabaseCount('notifications', 1);

        $this->postJson("/api/admin/store/products/{$productId}/movements", [
            'tipo' => 'salida',
            'cantidad' => 1,
            'motivo' => 'Ajuste de prueba',
        ])->assertOk();
        $this->assertDatabaseCount('notifications', 2);

        $notification = $admin->notifications()
            ->get()
            ->first(fn ($item) => $item->data['details']['status'] === 'out_of_stock');
        $this->assertNotNull($notification);
        $this->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('0.data.event_type', 'store_inventory_alert')
            ->assertJsonFragment(['status' => 'out_of_stock']);
        $this->patchJson("/api/admin/notifications/{$notification->id}/read")
            ->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_only_administrators_can_manage_store_coupons(): void
    {
        $payload = [
            'code' => 'PRUEBA15',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'active' => true,
        ];

        $this->postJson('/api/admin/store/coupons', $payload)->assertUnauthorized();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->postJson('/api/admin/store/coupons', [...$payload, 'discount_value' => 100.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_value']);

        $couponId = $this->actingAs($admin)
            ->postJson('/api/admin/store/coupons', $payload)
            ->assertCreated()
            ->assertJsonPath('code', 'PRUEBA15')
            ->json('id');

        $this->putJson("/api/admin/store/coupons/{$couponId}", [
            ...$payload,
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'active' => false,
        ])->assertOk()
            ->assertJsonPath('discount_type', 'fixed')
            ->assertJsonPath('active', false);

        $this->getJson('/api/admin/store')
            ->assertOk()
            ->assertJsonPath('coupons.0.code', 'PRUEBA15');
        $this->deleteJson("/api/admin/store/coupons/{$couponId}")
            ->assertOk();
        $this->assertDatabaseMissing('store_coupons', ['id' => $couponId]);
    }

    public function test_store_card_checkout_is_confirmed_only_after_mercado_pago_webhook_matches_amount_and_currency(): void
    {
        Storage::fake('uploads');
        Mail::fake();
        config([
            'services.mercadopago.access_token' => 'test-access-token',
            'services.mercadopago.environment' => 'sandbox',
            'services.mercadopago.return_url' => 'https://digitalpyme.test',
            'services.mercadopago.webhook_url' => 'https://api.digitalpyme.test/api/payments/mercadopago/webhook',
        ]);
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'preference-123',
                'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123',
            ], 201),
        ]);

        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'CARD-CHECKOUT',
            'nombre' => 'Producto con tarjeta',
            'precio' => 45,
            'stock' => 2,
        ]);

        $orderId = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'card',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'pendiente')
            ->json('id');

        $this->getJson('/api/store/payment-options')
            ->assertOk()
            ->assertJsonPath('card_enabled', true);

        $this->postJson("/api/client/store/orders/{$orderId}/payments/card")
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://sandbox.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123');
        $this->postJson("/api/client/store/orders/{$orderId}/payments/card")
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123');
        Http::assertSentCount(1);

        $this->actingAs($clientUser)->withHeader('Accept', 'application/json')->post("/api/client/store/orders/{$orderId}/receipt", [
            'file' => UploadedFile::fake()->create('card-proof.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->assertDatabaseHas('store_orders', ['id' => $orderId, 'payment_status' => 'pendiente']);

        $reference = StoreOrder::findOrFail($orderId)->provider_reference;
        Http::fake([
            'https://api.mercadopago.com/v1/payments/123456' => Http::sequence()
                ->push([
                    'external_reference' => $reference,
                    'transaction_amount' => 999,
                    'currency_id' => 'PEN',
                    'status' => 'approved',
                ])
                ->push([
                    'external_reference' => $reference,
                    'transaction_amount' => 67.26,
                    'currency_id' => 'USD',
                    'status' => 'approved',
                ])
                ->push([
                    'external_reference' => $reference,
                    'transaction_amount' => 67.26,
                    'currency_id' => 'PEN',
                    'status' => 'approved',
                ]),
        ]);
        $this->postJson('/api/payments/mercadopago/webhook', ['data' => ['id' => '123456']])->assertOk();
        $this->assertDatabaseHas('store_orders', ['id' => $orderId, 'payment_status' => 'pendiente']);
        $this->postJson('/api/payments/mercadopago/webhook', ['data' => ['id' => '123456']])->assertOk();
        $this->assertDatabaseHas('store_orders', ['id' => $orderId, 'payment_status' => 'pendiente']);
        $this->postJson('/api/payments/mercadopago/webhook', ['data' => ['id' => '123456']])->assertOk();
        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'payment_status' => 'pagado',
            'provider_payment_id' => '123456',
        ]);
        $this->assertNotNull(StoreOrder::findOrFail($orderId)->paid_at);
        Mail::assertSent(StoreOrderConfirmation::class, 2);
        Mail::assertSent(StoreOrderConfirmation::class, fn (StoreOrderConfirmation $mail) => $mail->order->payment_status === 'pagado');
    }

    public function test_demo_products_are_not_offered_for_public_sale(): void
    {
        $this->seed();

        $this->getJson('/api/store/products')
            ->assertOk()
            ->assertExactJson([]);

        $this->assertDatabaseHas('store_products', [
            'sku' => 'DEMO-TAZA-01',
            'activo' => false,
        ]);
        $this->assertDatabaseHas('store_products', [
            'sku' => 'DEMO-LIBRETA-01',
            'activo' => false,
        ]);
    }

    public function test_admin_can_upload_public_product_images_without_exposing_storage_paths(): void
    {
        Storage::fake('uploads');
        $admin = User::factory()->create(['is_admin' => true]);
        $product = StoreProduct::create([
            'sku' => 'PHOTO-01',
            'nombre' => 'Producto fotografiado',
            'categoria' => 'Accesorios',
            'precio' => 35,
            'stock' => 2,
            'activo' => true,
        ]);

        $this->actingAs($admin)->post("/api/admin/store/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('product.png', 10, 10),
        ])->assertOk()
            ->assertJsonPath('imagen_url', url("/api/store/products/{$product->id}/image"));

        $this->getJson('/api/store/products')
            ->assertOk()
            ->assertJsonPath('0.categoria', 'Accesorios')
            ->assertJsonPath('0.imagen_url', url("/api/store/products/{$product->id}/image"));

        $this->get("/api/store/products/{$product->id}/image")
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertDatabaseMissing('store_products', [
            'id' => $product->id,
            'image_path' => null,
        ]);
        $this->assertNotNull($product->fresh()->image_path);

        $this->actingAs($admin)->deleteJson("/api/admin/store/products/{$product->id}/image")
            ->assertOk();
        $this->assertNull($product->fresh()->image_path);
    }

    public function test_product_image_upload_is_admin_only_and_rejects_non_images(): void
    {
        $product = StoreProduct::create([
            'sku' => 'PHOTO-02',
            'nombre' => 'Producto sin foto',
            'precio' => 10,
            'stock' => 1,
        ]);

        $this->withHeader('Accept', 'application/json')->post("/api/admin/store/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('product.png'),
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post("/api/admin/store/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_admin_store_lists_are_paginated_independently_and_keep_array_response_shape(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_client' => true]);
        $client = Client::create(['user_id' => $user->id, 'nombre' => $user->name]);

        for ($index = 1; $index <= 3; $index++) {
            $product = StoreProduct::create([
                'sku' => "PAGE-{$index}",
                'nombre' => "Producto {$index}",
                'precio' => 10,
                'stock' => $index,
            ]);
            StockMovement::create([
                'product_id' => $product->id,
                'tipo' => 'entrada',
                'cantidad' => $index,
                'motivo' => 'Carga de prueba',
            ]);
            $order = StoreOrder::create([
                'client_id' => $client->id,
                'estado' => 'recibido',
                'tipo_operacion' => 'in-house',
                'metodo_envio' => 'estandar',
                'ubicacion_entrega' => 'Lima',
                'direccion_entrega' => 'Av. Demo 123',
                'subtotal' => 10,
                'costo_envio' => 0,
                'total' => 10,
            ]);
            StoreReturn::create([
                'order_id' => $order->id,
                'motivo' => "Motivo de prueba {$index}",
            ]);
        }

        $this->actingAs($admin)->getJson('/api/admin/store?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonCount(2, 'movements')
            ->assertJsonCount(2, 'orders')
            ->assertJsonCount(2, 'returns')
            ->assertJsonPath('pagination.products.total', 3)
            ->assertJsonPath('pagination.products.current_page', 1)
            ->assertJsonPath('pagination.products.last_page', 2)
            ->assertJsonPath('pagination.orders.total', 3)
            ->assertJsonPath('pagination.returns.per_page', 2);

        $this->getJson('/api/admin/store?per_page=2&products_page=2&orders_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonCount(2, 'movements')
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(2, 'returns')
            ->assertJsonPath('pagination.products.current_page', 2)
            ->assertJsonPath('pagination.movements.current_page', 1)
            ->assertJsonPath('pagination.orders.current_page', 2)
            ->assertJsonPath('pagination.returns.current_page', 1);

        $this->getJson('/api/admin/store?per_page=2&movements_page=2&returns_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonCount(1, 'movements')
            ->assertJsonCount(2, 'orders')
            ->assertJsonCount(1, 'returns')
            ->assertJsonPath('pagination.products.current_page', 1)
            ->assertJsonPath('pagination.movements.current_page', 2)
            ->assertJsonPath('pagination.orders.current_page', 1)
            ->assertJsonPath('pagination.returns.current_page', 2);
    }

    public function test_admin_store_pagination_rejects_page_sizes_over_the_limit(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/api/admin/store?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_checkout_records_stock_movement_and_applies_free_shipping_threshold(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $productId = $this->actingAs($admin)->postJson('/api/admin/store/products', [
            'sku' => 'DEMO-01',
            'nombre' => 'Producto de demostración',
            'precio' => 120,
            'stock' => 5,
            'punto_reorden' => 2,
            'ubicacion_almacen' => 'Pasillo A / Estante 2',
        ])->assertCreated()
            ->assertJsonPath('alerta_reorden', false)
            ->json('id');

        $user = User::factory()->create(['is_client' => true]);
        $client = Client::create(['user_id' => $user->id, 'nombre' => $user->name]);
        $this->actingAs($user)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $productId, 'cantidad' => 2]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('estado', 'recibido')
            ->assertJsonPath('payment_status', 'pendiente')
            ->assertJsonPath('tipo_operacion', 'in-house')
            ->assertJsonPath('costo_envio', '0.00');

        $this->assertDatabaseHas('store_products', ['id' => $productId, 'stock' => 3, 'estado_stock' => 'disponible']);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'tipo' => 'salida',
            'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('store_orders', ['client_id' => $client->id, 'total' => 283.2]);
    }

    public function test_checkout_calculates_decimal_prices_and_shipping_threshold_in_cents(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $product = StoreProduct::create([
            'sku' => 'DEMO-DECIMAL',
            'nombre' => 'Producto con céntimos',
            'precio' => '19.99',
            'stock' => 6,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);

        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => ['free_shipping_threshold' => '59.98'],
        ])->assertOk();

        $payload = [
            'items' => [['product_id' => $product->id, 'cantidad' => 3]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ];
        $this->actingAs($clientUser)->postJson('/api/client/store/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('subtotal', '59.97')
            ->assertJsonPath('costo_envio', '12.00')
            ->assertJsonPath('igv_amount', '12.95')
            ->assertJsonPath('total', '84.92')
            ->assertJsonPath('items.0.precio_unitario', '19.99')
            ->assertJsonPath('items.0.subtotal', '59.97');

        $this->actingAs($admin)->putJson('/api/admin/site-settings', [
            'coverage' => ['free_shipping_threshold' => '59.97'],
        ])->assertOk();

        $this->actingAs($clientUser)->postJson('/api/client/store/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('subtotal', '59.97')
            ->assertJsonPath('costo_envio', '0.00')
            ->assertJsonPath('igv_amount', '10.79')
            ->assertJsonPath('total', '70.76');
    }

    public function test_manual_store_payment_requires_receipt_review_before_fulfillment(): void
    {
        Storage::fake('uploads');
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $clientUser = User::factory()->create(['is_client' => true]);
        $client = Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'PAYMENT-REVIEW',
            'nombre' => 'Producto con pago pendiente',
            'precio' => 45,
            'stock' => 2,
        ]);

        $orderId = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'yape_plin',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'pendiente')
            ->json('id');

        $this->actingAs($admin)->getJson('/api/admin/store')
            ->assertOk()
            ->assertJsonPath('metrics.units_sold', 0);

        $this->actingAs($admin)->patchJson("/api/admin/store/orders/{$orderId}", [
            'estado' => 'en_picking',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);

        $this->actingAs($clientUser)->post("/api/client/store/orders/{$orderId}/receipt", [
            'file' => UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'comprobante_enviado');

        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'client_id' => $client->id,
            'payment_status' => 'comprobante_enviado',
        ]);

        StoreOrder::query()->whereKey($orderId)->update(['payment_due_at' => now()->subMinute()]);
        $this->artisan('store:expire-unpaid-orders')->assertExitCode(0);
        $this->assertDatabaseHas('store_orders', ['id' => $orderId, 'payment_status' => 'comprobante_enviado']);
        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 1]);

        $otherUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $otherUser->id, 'nombre' => $otherUser->name]);
        $this->actingAs($otherUser)->get("/api/client/store/orders/{$orderId}/receipt")->assertNotFound();

        $this->actingAs($admin)->get("/api/admin/store/orders/{$orderId}/receipt")->assertOk();
        $this->patchJson("/api/admin/store/orders/{$orderId}/payment", [
            'payment_status' => 'rechazado',
        ])->assertOk()->assertJsonPath('payment_status', 'rechazado');

        $this->actingAs($clientUser)->post("/api/client/store/orders/{$orderId}/receipt", [
            'file' => UploadedFile::fake()->create('nuevo-comprobante.pdf', 100, 'application/pdf'),
        ])->assertCreated();
        $this->actingAs($admin)->patchJson("/api/admin/store/orders/{$orderId}/payment", [
            'payment_status' => 'pagado',
        ])->assertOk()
            ->assertJsonPath('payment_status', 'pagado')
            ->assertJsonPath('payment_reviewed_by', $admin->id)
            ->assertJsonPath('payment_review_history.0.new_status', 'pagado')
            ->assertJsonPath('receipt_email_sent', false);
        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'payment_reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseCount('manual_payment_reviews', 2);
        Mail::assertSent(StoreOrderConfirmation::class, 2);
        Mail::assertSent(StoreOrderConfirmation::class, fn (StoreOrderConfirmation $mail) => $mail->order->payment_status === 'pagado');

        $this->getJson('/api/admin/store')
            ->assertOk()
            ->assertJsonPath('metrics.units_sold', 1);
        $this->patchJson("/api/admin/store/orders/{$orderId}", ['estado' => 'en_picking'])->assertOk();
        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'payment_status' => 'pagado',
            'payment_due_at' => null,
            'estado' => 'en_picking',
        ]);
    }

    public function test_unpaid_store_orders_expire_and_release_reserved_stock(): void
    {
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'PAYMENT-EXPIRY',
            'nombre' => 'Producto con reserva temporal',
            'precio' => 35,
            'stock' => 2,
        ]);

        $orderId = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'pendiente')
            ->json('id');

        StoreOrder::query()->whereKey($orderId)->update(['payment_due_at' => now()->subMinute()]);
        $this->artisan('store:expire-unpaid-orders')
            ->expectsOutput('1 pedidos vencidos; stock reservado liberado.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('store_orders', [
            'id' => $orderId,
            'payment_status' => 'expirado',
            'estado' => 'cancelado',
        ]);
        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 2]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'order_id' => $orderId,
            'tipo' => 'entrada',
            'cantidad' => 1,
            'motivo' => "Pedido #{$orderId} vencido por falta de pago",
        ]);
    }

    public function test_product_prices_reject_more_than_two_decimal_places(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/api/admin/store/products', [
                'sku' => 'DEMO-PRECISION',
                'nombre' => 'Precio con precisión inválida',
                'precio' => '19.999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['precio']);
    }

    public function test_checkout_applies_percentage_coupon_saves_invoice_data_and_emails_order_summary(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $clientUser = User::factory()->create(['is_client' => true]);
        $client = Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'COUPON-01',
            'nombre' => 'Producto con cupón',
            'precio' => 100,
            'stock' => 2,
        ]);
        StoreCoupon::create([
            'code' => 'BIENVENIDA10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'active' => true,
        ]);

        $this->actingAs($clientUser)->postJson('/api/client/store/coupons/validate', [
            'code' => 'bienvenida10',
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
        ])->assertOk()
            ->assertJsonPath('code', 'BIENVENIDA10')
            ->assertJsonPath('discount_amount', '10.00');

        $response = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'tipo_comprobante' => 'factura',
            'tipo_documento' => 'ruc',
            'numero_documento' => '20123456789',
            'empresa_facturacion' => 'Empresa de prueba S.A.C.',
            'coupon_code' => 'bienvenida10',
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Central 123',
        ])->assertCreated()
            ->assertJsonPath('discount_amount', '10.00')
            ->assertJsonPath('coupon_code', 'BIENVENIDA10')
            ->assertJsonPath('igv_amount', '18.36')
            ->assertJsonPath('total', '120.36')
            ->assertJsonPath('tipo_comprobante', 'factura')
            ->assertJsonPath('numero_documento', '20123456789')
            ->assertJsonPath('email_enviado', true);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'telefono' => '999888777',
            'tipo_documento' => 'ruc',
            'numero_documento' => '20123456789',
            'tipo_comprobante' => 'factura',
        ]);
        Mail::assertSent(StoreOrderConfirmation::class, fn (StoreOrderConfirmation $mail) => $mail->order->email_cliente === $clientUser->email);
    }

    public function test_invoice_checkout_requires_a_matching_document_number_and_ruc_for_invoice(): void
    {
        $user = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $user->id, 'nombre' => $user->name]);
        $product = StoreProduct::create([
            'sku' => 'INVOICE-VALIDATION',
            'nombre' => 'Producto para validar factura',
            'precio' => 20,
            'stock' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/client/store/orders', [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'tipo_comprobante' => 'factura',
            'empresa_facturacion' => 'Empresa de prueba S.A.C.',
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Central 123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo_documento']);

        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_checkout_locks_products_in_id_order_without_changing_requested_item_order(): void
    {
        $firstProduct = StoreProduct::create([
            'sku' => 'DEMO-ORDER-01',
            'nombre' => 'Primer producto',
            'precio' => '10.25',
            'stock' => 3,
        ]);
        $secondProduct = StoreProduct::create([
            'sku' => 'DEMO-ORDER-02',
            'nombre' => 'Segundo producto',
            'precio' => '20.50',
            'stock' => 3,
        ]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);

        $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [
                ['product_id' => $secondProduct->id, 'cantidad' => 1],
                ['product_id' => $firstProduct->id, 'cantidad' => 2],
            ],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 123',
        ])->assertCreated()
            ->assertJsonPath('items.0.sku', $secondProduct->sku)
            ->assertJsonPath('items.1.sku', $firstProduct->sku)
            ->assertJsonPath('subtotal', '41.00');

        $this->assertDatabaseHas('store_products', ['id' => $firstProduct->id, 'stock' => 1]);
        $this->assertDatabaseHas('store_products', ['id' => $secondProduct->id, 'stock' => 2]);
    }

    public function test_same_day_shipping_is_lima_only_and_order_cannot_exceed_available_stock(): void
    {
        $user = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $user->id, 'nombre' => $user->name]);
        $product = StoreProduct::create([
            'sku' => 'DEMO-02',
            'nombre' => 'Stock limitado',
            'precio' => 25,
            'stock' => 1,
            'punto_reorden' => 1,
        ]);

        $payload = [
            'items' => [['product_id' => $product->id, 'cantidad' => 1]],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'same_day',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Arequipa',
            'direccion_entrega' => 'Calle Demo 1',
        ];
        $this->actingAs($user)->postJson('/api/client/store/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metodo_envio']);

        $payload['metodo_envio'] = 'estandar';
        $payload['ubicacion_entrega'] = 'Lima';
        $this->actingAs($user)->postJson('/api/client/store/orders', $payload)->assertCreated();
        $payload['metodo_envio'] = 'express';
        $this->actingAs($user)->postJson('/api/client/store/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 0, 'estado_stock' => 'agotado']);
    }

    public function test_return_restocking_is_recorded_only_after_reventa_resolution(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $clientUser = User::factory()->create(['is_client' => true]);
        Client::create(['user_id' => $clientUser->id, 'nombre' => $clientUser->name]);
        $product = StoreProduct::create([
            'sku' => 'DEMO-03',
            'nombre' => 'Producto retornable',
            'precio' => 30,
            'stock' => 2,
            'punto_reorden' => 1,
        ]);
        $secondProduct = StoreProduct::create([
            'sku' => 'DEMO-04',
            'nombre' => 'Segundo producto retornable',
            'precio' => 45,
            'stock' => 4,
            'punto_reorden' => 1,
        ]);

        $orderId = $this->actingAs($clientUser)->postJson('/api/client/store/orders', [
            'items' => [
                ['product_id' => $secondProduct->id, 'cantidad' => 1],
                ['product_id' => $product->id, 'cantidad' => 1],
            ],
            ...$this->checkoutIdentity(),
            'metodo_envio' => 'estandar',
            'payment_method' => 'bank_transfer',
            'ubicacion_entrega' => 'Lima',
            'direccion_entrega' => 'Av. Demo 10',
        ])->assertCreated()->json('id');

        Storage::fake('uploads');
        $this->actingAs($clientUser)->post("/api/client/store/orders/{$orderId}/receipt", [
            'file' => UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
        ])->assertCreated();
        $this->actingAs($admin)->patchJson("/api/admin/store/orders/{$orderId}/payment", [
            'payment_status' => 'pagado',
        ])->assertOk();
        $this->patchJson("/api/admin/store/orders/{$orderId}", ['estado' => 'en_picking'])->assertOk();
        $this->patchJson("/api/admin/store/orders/{$orderId}", ['estado' => 'empacado'])->assertOk();
        $this->patchJson("/api/admin/store/orders/{$orderId}", ['estado' => 'enviado', 'codigo_rastreo' => 'TRK-123'])->assertOk();
        $this->patchJson("/api/admin/store/orders/{$orderId}", ['estado' => 'entregado'])->assertOk();

        $returnId = $this->actingAs($clientUser)->postJson("/api/client/store/orders/{$orderId}/returns", [
            'motivo' => 'El producto llegó con el empaque dañado.',
        ])->assertCreated()->json('id');

        $this->actingAs($admin)->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'aprobada'])->assertOk();
        $this->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'recibida'])->assertOk();
        $this->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'inspeccionada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notas_inspeccion']);
        $this->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'inspeccionada', 'notas_inspeccion' => 'ok'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notas_inspeccion']);
        $this->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'inspeccionada', 'notas_inspeccion' => 'Producto en buen estado.'])->assertOk();
        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 1]);
        $this->assertDatabaseHas('store_products', ['id' => $secondProduct->id, 'stock' => 3]);

        $this->patchJson("/api/admin/store/returns/{$returnId}", ['estado' => 'resuelta', 'resolucion' => 'reventa'])->assertOk();
        $this->assertDatabaseHas('store_products', ['id' => $product->id, 'stock' => 2]);
        $this->assertDatabaseHas('store_products', ['id' => $secondProduct->id, 'stock' => 4]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'tipo' => 'entrada',
            'cantidad' => 1,
            'motivo' => "Devolución #{$returnId} apta para reventa",
        ]);

        $this->getJson('/api/admin/store')
            ->assertOk()
            ->assertJsonPath('metrics.return_resolutions.reventa', 1);
    }
}
