<?php

namespace Tests\Feature;

use App\Mail\CotizacionEnviada;
use App\Models\Client;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\User;
use App\Support\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CotizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_quote_for_a_lead_and_move_it_to_quoted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        $response = $this->postJson("/api/leads/{$lead->id}/cotizaciones", [
            'servicio' => 'Página web',
            'descripcion' => 'Sitio corporativo',
            'monto' => 1800,
        ]);

        $response->assertCreated()
            ->assertJsonPath('lead_id', $lead->id)
            ->assertJsonPath('servicio', 'Página web')
            ->assertJsonPath('estado', 'borrador')
            ->assertJsonPath('monto', '1800.00');

        $this->assertDatabaseHas('cotizaciones', [
            'lead_id' => $lead->id,
            'servicio' => 'Página web',
            'estado' => 'borrador',
        ]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'contactado']);
    }

    public function test_invalid_quote_data_is_rejected_without_creating_a_quote(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead();

        $this->postJson("/api/leads/{$lead->id}/cotizaciones", [
            'servicio' => '',
            'monto' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['servicio', 'monto']);

        $this->assertDatabaseCount('cotizaciones', 0);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'nuevo']);
    }

    public function test_accepting_a_quote_marks_the_lead_as_won(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['estado' => 'cotizado']);
        $quote = $this->createQuote($lead);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertOk()
            ->assertJsonPath('estado', 'aceptada');

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'ganado']);
    }

    public function test_sending_a_quote_emails_a_pdf_summary_to_the_customer(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $quote = $this->createQuote($this->createLead());

        $this->patchJson("/api/cotizaciones/{$quote->id}", [
            'servicio' => 'Página web empresarial',
            'descripcion' => 'Diseño, desarrollo y capacitación confirmados.',
            'monto' => 2400,
            'estado' => 'enviada',
        ])
            ->assertOk()
            ->assertJsonPath('estado', 'enviada')
            ->assertJsonPath('monto', '2400.00')
            ->assertJsonPath('email_enviado', true);

        Mail::assertSent(CotizacionEnviada::class, fn (CotizacionEnviada $mail) => $mail->cotizacion->id === $quote->id);
        $this->assertDatabaseHas('leads', ['id' => $quote->lead_id, 'estado' => 'cotizado']);
        $pdf = CotizacionPdf::render($quote->fresh());
        $this->assertStringStartsWith('%PDF-', $pdf);

        $this->postJson("/api/cotizaciones/{$quote->id}/reenviar")
            ->assertOk()
            ->assertJsonPath('email_enviado', true);

        Mail::assertSent(CotizacionEnviada::class, 2);
    }

    public function test_quote_delivery_reports_failure_when_only_the_log_mailer_is_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $quote = $this->createQuote($this->createLead());

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'enviada'])
            ->assertOk()
            ->assertJsonPath('estado', 'enviada')
            ->assertJsonPath('email_enviado', false)
            ->assertJsonPath(
                'mensaje',
                'La cotización quedó marcada como enviada, pero no se pudo entregar el correo. Configura y verifica el servicio SMTP antes de informar al cliente.'
            );

        $this->postJson("/api/cotizaciones/{$quote->id}/reenviar")
            ->assertOk()
            ->assertJsonPath('email_enviado', false)
            ->assertJsonPath(
                'mensaje',
                'No se pudo reenviar la cotización. Verifica la configuración SMTP y vuelve a intentarlo.'
            );
    }

    public function test_client_can_list_and_accept_only_its_sent_quotes_once(): void
    {
        $lead = $this->createLead();
        $quote = $this->createQuote($lead, ['estado' => 'enviada']);
        $otherLead = $this->createLead(['nombre' => 'Otro cliente', 'email' => 'otro@example.com']);
        $this->createQuote($otherLead, ['estado' => 'enviada']);
        $user = User::factory()->create([
            'email' => $lead->email,
            'is_client' => true,
        ]);
        $client = Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);

        $this->actingAs($user)
            ->getJson('/api/client/cotizaciones')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $quote->id);

        $response = $this->postJson("/api/client/cotizaciones/{$quote->id}/respuesta", [
            'respuesta' => 'aceptada',
        ])->assertOk()
            ->assertJsonPath('estado', 'aceptada')
            ->assertJsonPath('lead_id', $lead->id);

        $this->assertNotNull($response->json('responded_at'));
        $this->assertDatabaseHas('cotizaciones', [
            'id' => $quote->id,
            'estado' => 'aceptada',
        ]);
        $this->assertDatabaseHas('projects', [
            'cotizacion_id' => $quote->id,
            'client_id' => $client->id,
            'estado' => 'planificacion',
        ]);
        $this->assertDatabaseHas('project_monthly_capacities', [
            'month' => now()->startOfMonth()->toDateString(),
            'reserved' => 1,
        ]);

        $this->postJson("/api/client/cotizaciones/{$quote->id}/respuesta", [
            'respuesta' => 'aceptada',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['cotizacion']);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_client_can_reject_its_sent_quote_without_creating_a_project(): void
    {
        $lead = $this->createLead();
        $quote = $this->createQuote($lead, ['estado' => 'enviada']);
        $user = User::factory()->create([
            'email' => $lead->email,
            'is_client' => true,
        ]);
        Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/client/cotizaciones/{$quote->id}/respuesta", [
                'respuesta' => 'rechazada',
            ])->assertOk()
            ->assertJsonPath('estado', 'rechazada');

        $this->assertNotNull($response->json('responded_at'));
        $this->assertDatabaseMissing('projects', ['cotizacion_id' => $quote->id]);
    }

    public function test_client_cannot_view_or_respond_to_another_clients_quote(): void
    {
        $lead = $this->createLead(['email' => 'owner@example.com']);
        $quote = $this->createQuote($lead, ['estado' => 'enviada']);
        $user = User::factory()->create([
            'email' => 'different@example.com',
            'is_client' => true,
        ]);
        Client::create(['user_id' => $user->id, 'nombre' => 'Otro cliente']);

        $this->actingAs($user)
            ->getJson('/api/client/cotizaciones')
            ->assertOk()
            ->assertJsonCount(0);

        $this->postJson("/api/client/cotizaciones/{$quote->id}/respuesta", [
            'respuesta' => 'aceptada',
        ])->assertNotFound();

        $this->assertDatabaseHas('cotizaciones', [
            'id' => $quote->id,
            'estado' => 'enviada',
            'responded_at' => null,
        ]);
        $this->assertDatabaseMissing('projects', ['cotizacion_id' => $quote->id]);
    }

    public function test_quote_without_a_confirmed_amount_cannot_be_sent_or_accepted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $quote = $this->createQuote($this->createLead(), ['monto' => 0]);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'enviada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto']);
        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto']);

        $this->assertDatabaseHas('cotizaciones', ['id' => $quote->id, 'estado' => 'borrador']);
    }

    public function test_quote_without_a_defined_scope_cannot_be_sent_or_accepted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $quote = $this->createQuote($this->createLead(), ['descripcion' => null]);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'enviada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['descripcion']);
        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'aceptada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['descripcion']);
    }

    public function test_rejecting_the_last_accepted_quote_returns_a_won_lead_to_quoted(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['estado' => 'ganado']);
        $quote = $this->createQuote($lead, ['estado' => 'aceptada']);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'rechazada'])
            ->assertOk()
            ->assertJsonPath('estado', 'rechazada');

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'cotizado']);
    }

    public function test_another_accepted_quote_keeps_the_lead_won(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lead = $this->createLead(['estado' => 'cotizado']);
        $acceptedQuote = $this->createQuote($lead);
        $this->patchJson("/api/cotizaciones/{$acceptedQuote->id}", ['estado' => 'aceptada'])
            ->assertOk();
        $quote = $this->createQuote($lead);

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'rechazada'])
            ->assertOk();

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'estado' => 'ganado']);
        $this->assertDatabaseMissing('projects', ['cotizacion_id' => $quote->id]);
        $this->assertDatabaseHas('projects', ['cotizacion_id' => $acceptedQuote->id]);
    }

    public function test_invalid_quote_status_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $quote = $this->createQuote($this->createLead());

        $this->patchJson("/api/cotizaciones/{$quote->id}", ['estado' => 'cancelada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);
    }

    private function createLead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'nombre' => 'Cliente de prueba',
            'email' => 'cliente@example.com',
            'servicio' => 'Página web',
        ], $attributes));
    }

    private function createQuote(Lead $lead, array $attributes = []): Cotizacion
    {
        return $lead->cotizaciones()->create(array_merge([
            'servicio' => 'Página web',
            'descripcion' => 'Diseño, desarrollo y puesta en marcha del sitio.',
            'monto' => 1800,
        ], $attributes));
    }
}
