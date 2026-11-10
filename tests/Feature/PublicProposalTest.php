<?php

namespace Tests\Feature;

use App\Mail\CodigoVerificacionCotizacion;
use App\Mail\SolicitudRecibida;
use App\Models\Lead;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp']);
    }

    public function test_public_proposal_creates_lead_and_quote_using_server_side_prices(): void
    {
        Mail::fake();
        $this->seed();
        Servicio::where('nombre', 'Páginas web')->update(['precio_desde' => 1800]);
        $payload = $this->verifiedProposalPayload('ana@example.com');

        $response = $this->postJson('/api/propuestas', [
            ...$payload,
            'empresa' => 'Tienda de Ana',
            'servicios' => ['Páginas web', 'Tiendas en línea'],
            'metodo_pago_preferido' => 'card',
            'mensaje' => 'Necesito vender mis productos en línea.',
            'inversion_estimada' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('inversion_estimada', 1800)
            ->assertJsonPath('incluye_servicios_a_cotizar', true)
            ->assertJsonPath('metodo_pago_preferido', 'card')
            ->assertJsonPath('email_enviado', true);

        $this->assertDatabaseHas('leads', [
            'nombre' => 'Ana Pérez',
            'servicio' => 'Propuesta personalizada',
            'inversion_estimada' => 1800,
        ]);
        $this->assertDatabaseHas('cotizaciones', [
            'lead_id' => Lead::where('email', 'ana@example.com')->value('id'),
            'servicio' => 'Proyecto personalizado',
            'monto' => 1800,
            'estado' => 'borrador',
        ]);
        $lead = Lead::where('email', 'ana@example.com')->firstOrFail();
        $quote = $lead->cotizaciones()->firstOrFail();
        $this->assertStringContainsString('Consentimientos registrados:', $lead->mensaje);
        $this->assertStringContainsString('Método de pago preferido (referencial): Tarjeta (Mercado Pago)', $lead->mensaje);
        $this->assertStringContainsString('Necesito vender mis productos en línea.', $quote->descripcion);
        $this->assertStringNotContainsString('Consentimientos registrados:', $quote->descripcion);

        Mail::assertSent(SolicitudRecibida::class, function (SolicitudRecibida $mail) {
            return $mail->lead->email === 'ana@example.com'
                && $mail->cotizacion?->monto === '1800.00';
        });

        $this->postJson('/api/propuestas', [
            ...$payload,
            'empresa' => 'Tienda de Ana',
            'servicios' => ['Páginas web', 'Tiendas en línea'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['verification_token']);

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_invalid_or_inactive_services_do_not_create_a_lead_or_quote(): void
    {
        Mail::fake();
        $this->seed();
        $payload = $this->verifiedProposalPayload('ana@example.com');
        Servicio::where('nombre', 'Páginas web')->update(['activo' => false]);

        $this->postJson('/api/propuestas', [
            ...$payload,
            'servicios' => ['Páginas web'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['servicios']);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('cotizaciones', 0);
    }

    public function test_contact_lead_stores_estimate_from_the_active_service_and_notifies(): void
    {
        Mail::fake();
        $this->seed();
        Servicio::where('nombre', 'Branding')->update(['precio_desde' => 1200]);

        $response = $this->postJson('/api/leads', [
            'nombre' => 'Luis Rojas',
            'telefono' => '987654321',
            'servicio' => 'Branding',
            'inversion_estimada' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('lead.inversion_estimada', '1200.00')
            ->assertJsonPath('email_enviado', true);

        $this->assertDatabaseHas('leads', [
            'nombre' => 'Luis Rojas',
            'servicio' => 'Branding',
            'inversion_estimada' => 1200,
        ]);
        Mail::assertSent(SolicitudRecibida::class, fn (SolicitudRecibida $mail) => $mail->lead->nombre === 'Luis Rojas');
    }

    public function test_seeded_services_have_no_unconfirmed_prices_or_timelines(): void
    {
        $this->seed();

        $this->getJson('/api/servicios')->assertOk()
            ->assertJsonPath('0.precio_desde', null)
            ->assertJsonPath('0.duracion', null);

        $this->assertSame(0, Servicio::whereNotNull('precio_desde')->count());
        $this->assertSame(0, Servicio::whereNotNull('duracion')->count());
    }

    public function test_proposal_whatsapp_link_uses_only_the_configured_public_contact(): void
    {
        Mail::fake();
        $this->seed();
        $payload = [
            ...$this->verifiedProposalPayload('cliente@example.com'),
            'nombre' => 'Cliente de prueba',
            'servicios' => ['Páginas web'],
        ];

        $this->postJson('/api/propuestas', $payload)
            ->assertCreated()
            ->assertJsonPath('whatsapp_url', null);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->putJson('/api/admin/site-settings', [
                'contact' => ['whatsapp_phone' => '+51912345678'],
            ])->assertOk();

        $response = $this->postJson('/api/propuestas', [
            ...$this->verifiedProposalPayload('otro@example.com'),
            'email' => 'otro@example.com',
            'nombre' => 'Cliente de prueba',
            'servicios' => ['Páginas web'],
        ])->assertCreated();

        $this->assertStringStartsWith(
            'https://wa.me/51912345678?text=',
            $response->json('whatsapp_url'),
        );

        parse_str((string) parse_url($response->json('whatsapp_url'), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('Inversión pendiente de cotizar.', $query['text']);
        $this->assertStringNotContainsString('S/ 0.00', $query['text']);
    }

    public function test_proposal_without_published_prices_is_marked_as_pending_instead_of_zero(): void
    {
        Mail::fake();
        $this->seed();

        $this->postJson('/api/propuestas', [
            ...$this->verifiedProposalPayload('cliente@example.com'),
            'servicios' => ['Páginas web'],
        ])->assertCreated()
            ->assertJsonPath('inversion_estimada', null)
            ->assertJsonPath('incluye_servicios_a_cotizar', true);

        $this->assertDatabaseHas('leads', [
            'email' => 'cliente@example.com',
            'inversion_estimada' => null,
        ]);

        Mail::assertSent(SolicitudRecibida::class, function (SolicitudRecibida $mail) {
            $html = $mail->render();

            return str_contains($html, 'Pendiente de cotización.')
                && ! str_contains($html, 'S/ 0.00');
        });
    }

    public function test_public_proposal_requires_verified_email_registration_and_consent(): void
    {
        $this->seed();

        $this->postJson('/api/propuestas', [
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'empresa' => 'Tienda de Ana',
            'pais' => 'Perú',
            'email' => 'ana@example.com',
            'telefono' => '+51912345678',
            'servicios' => ['Páginas web'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['verification_token', 'acepta_terminos', 'acepta_privacidad']);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('cotizaciones', 0);
    }

    public function test_public_proposal_rejects_unknown_payment_preferences(): void
    {
        Mail::fake();
        $this->seed();
        $payload = $this->verifiedProposalPayload('ana@example.com');

        $this->postJson('/api/propuestas', [
            ...$payload,
            'servicios' => ['Páginas web'],
            'metodo_pago_preferido' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['metodo_pago_preferido']);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('cotizaciones', 0);
    }

    public function test_public_proposal_records_yape_plin_and_paypal_payment_preferences(): void
    {
        Mail::fake();
        $this->seed();

        foreach ([
            'yape' => 'Yape (número o QR)',
            'plin' => 'Plin',
            'paypal' => 'PayPal (demo)',
        ] as $method => $label) {
            if ($method === 'plin') {
                $this->travel(61)->seconds();
            } elseif ($method === 'paypal') {
                $this->travel(61)->seconds();
            }

            $email = "{$method}@example.com";
            $this->postJson('/api/propuestas', [
                ...$this->verifiedProposalPayload($email),
                'servicios' => ['Páginas web'],
                'metodo_pago_preferido' => $method,
            ])->assertCreated()
                ->assertJsonPath('metodo_pago_preferido', $method);

            $lead = Lead::where('email', $email)->firstOrFail();
            $this->assertStringContainsString("Método de pago preferido (referencial): {$label}", $lead->mensaje);
        }

        $this->assertDatabaseCount('leads', 3);
        $this->assertDatabaseCount('cotizaciones', 3);
    }

    private function verifiedProposalPayload(string $email): array
    {
        $this->postJson('/api/propuestas/codigo', [
            'email' => $email,
            'website' => '',
        ])->assertOk();

        $code = null;
        Mail::assertSent(CodigoVerificacionCotizacion::class, function (CodigoVerificacionCotizacion $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $verification = $this->postJson('/api/propuestas/verificar-codigo', [
            'email' => $email,
            'code' => $code,
            'website' => '',
        ])->assertOk();

        return [
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'empresa' => 'Tienda de Ana',
            'pais' => 'Perú',
            'email' => $email,
            'telefono' => '+51912345678',
            'verification_token' => $verification->json('verification_token'),
            'acepta_terminos' => true,
            'acepta_privacidad' => true,
            'website' => '',
        ];
    }
}
