<?php

namespace Tests\Feature;

use App\Mail\PagoConfirmado;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_only_view_and_pay_for_its_own_accepted_project(): void
    {
        [$user, $project] = $this->clientProject('client@example.com');
        [, $otherProject] = $this->clientProject('other@example.com');

        $this->actingAs($user)
            ->getJson("/api/client/projects/{$project->id}/payments")
            ->assertOk()
            ->assertJsonPath('payments', [])
            ->assertJsonPath('card_enabled', false);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$otherProject->id}/payments/transfers")
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers")
            ->assertCreated()
            ->assertJsonPath('payment.amount', '1800.00')
            ->assertJsonPath('payment.currency', 'PEN')
            ->assertJsonPath('payment.status', 'pendiente');
    }

    public function test_client_cannot_pay_until_the_quote_is_accepted(): void
    {
        [$user, $project] = $this->clientProject();
        $project->cotizacion->update(['estado' => 'enviada']);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);

        $this->assertDatabaseCount('project_payments', 0);
    }

    public function test_client_can_submit_a_private_transfer_receipt_for_admin_review(): void
    {
        Storage::fake('uploads');
        Mail::fake();
        config(['mail.default' => 'smtp']);
        [$user, $project] = $this->clientProject();
        $payment = $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers")
            ->assertCreated()
            ->json('payment');

        $receipt = UploadedFile::fake()->createWithContent('transfer.pdf', '%PDF-1.4 transfer');
        $this->actingAs($user)
            ->post("/api/client/projects/{$project->id}/payments/{$payment['id']}/receipt", ['file' => $receipt])
            ->assertCreated()
            ->assertJsonPath('tipo', 'comprobante')
            ->assertJsonMissingPath('file_path');

        $this->assertDatabaseHas('project_payments', [
            'id' => $payment['id'],
            'status' => 'comprobante_enviado',
        ]);
        $document = ProjectDocument::where('payment_id', $payment['id'])->firstOrFail();
        Storage::disk('uploads')->assertExists($document->file_path);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->patchJson("/api/admin/payments/{$payment['id']}", ['status' => 'pagado'])
            ->assertOk()
            ->assertJsonPath('status', 'pagado')
            ->assertJsonPath('receipt_email_sent', true);

        $this->actingAs($admin)
            ->patchJson("/api/admin/payments/{$payment['id']}", ['status' => 'rechazado'])
            ->assertUnprocessable();

        Mail::assertSent(PagoConfirmado::class, fn (PagoConfirmado $mail) => $mail->payment->id === $payment['id']);
        [$otherUser, $otherProject] = $this->clientProject('other@example.com');
        $this->actingAs($otherUser)
            ->get("/api/client/projects/{$otherProject->id}/payments/{$payment['id']}/receipt")
            ->assertNotFound();

        $receiptResponse = $this->actingAs($user)
            ->get("/api/client/projects/{$project->id}/payments/{$payment['id']}/receipt");
        $receiptResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $receiptResponse->getContent());
    }

    public function test_client_can_submit_yape_plin_payment_with_receipt_for_admin_review(): void
    {
        Storage::fake('uploads');
        config([
            'services.yape_plin.enabled' => true,
            'services.yape_plin.phone' => '999888777',
            'services.yape_plin.holder' => 'DigitalPyme',
            'services.yape_plin.qr_url' => 'https://example.com/yape-qr.png',
        ]);
        [$user, $project] = $this->clientProject();

        $this->actingAs($user)
            ->getJson("/api/client/projects/{$project->id}/payments")
            ->assertOk()
            ->assertJsonPath('yape_plin.enabled', true)
            ->assertJsonPath('yape_plin.phone', '999888777')
            ->assertJsonPath('yape_plin.holder', 'DigitalPyme')
            ->assertJsonPath('yape_plin.qr_url', 'https://example.com/yape-qr.png');

        $payment = $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers", ['method' => 'yape_plin'])
            ->assertCreated()
            ->assertJsonPath('payment.method', 'yape_plin')
            ->json('payment');

        $receipt = UploadedFile::fake()->createWithContent('yape.png', 'yape receipt');
        $this->actingAs($user)
            ->post("/api/client/projects/{$project->id}/payments/{$payment['id']}/receipt", ['file' => $receipt])
            ->assertCreated();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->patchJson("/api/admin/payments/{$payment['id']}", ['status' => 'pagado'])
            ->assertOk()
            ->assertJsonPath('status', 'pagado');
    }

    public function test_yape_plin_payment_requires_server_configuration(): void
    {
        config([
            'services.yape_plin.enabled' => false,
            'services.yape_plin.phone' => null,
        ]);
        [$user, $project] = $this->clientProject();

        $this->actingAs($user)
            ->getJson("/api/client/projects/{$project->id}/payments")
            ->assertOk()
            ->assertJsonPath('yape_plin.enabled', false);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers", ['method' => 'yape_plin'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'yape_plin_not_configured');
    }

    public function test_milestone_payment_requires_approval_and_cannot_exceed_the_accepted_quote(): void
    {
        [$user, $project] = $this->clientProject();
        $first = $project->milestones()->create([
            'titulo' => 'Primer hito',
            'monto' => 1400,
            'estado' => 'aprobado',
        ]);
        $second = $project->milestones()->create([
            'titulo' => 'Segundo hito',
            'monto' => 500,
            'estado' => 'aprobado',
        ]);
        $pending = $project->milestones()->create([
            'titulo' => 'Hito pendiente',
            'monto' => 100,
            'estado' => 'pendiente_aprobacion',
        ]);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers", ['milestone_id' => $pending->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['milestone_id']);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers", ['milestone_id' => $first->id])
            ->assertCreated()
            ->assertJsonPath('payment.amount', '1400.00');

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers", ['milestone_id' => $second->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['milestone_id']);

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/transfers")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);
    }

    public function test_card_checkout_is_unavailable_without_credentials_and_verifies_provider_payment(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        [$user, $project] = $this->clientProject();

        $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/card")
            ->assertStatus(503)
            ->assertJsonPath('code', 'payment_provider_not_configured');

        config(['services.mercadopago.access_token' => 'test-token']);
        config(['services.mercadopago.return_url' => 'http://localhost:8443']);
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'preference-123',
                'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123',
            ], 201),
        ]);

        $checkout = $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/card")
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-123');
        $payment = $checkout->json('payment');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/checkout/preferences'
            && $request['external_reference'] === $payment['reference']);

        Http::fake([
            'https://api.mercadopago.com/v1/payments/987654' => Http::response([
                'external_reference' => $payment['reference'],
                'transaction_amount' => 1800,
                'currency_id' => 'PEN',
                'status' => 'approved',
            ]),
        ]);

        $this->postJson('/api/payments/mercadopago/webhook', ['data' => ['id' => '987654']])
            ->assertOk();

        $this->assertDatabaseHas('project_payments', [
            'id' => $payment['id'],
            'status' => 'pagado',
            'provider_reference' => '987654',
        ]);
        Mail::assertSent(PagoConfirmado::class, fn (PagoConfirmado $mail) => $mail->payment->id === $payment['id']);
        $receiptResponse = $this->actingAs($user)
            ->get("/api/client/projects/{$project->id}/payments/{$payment['id']}/receipt");
        $receiptResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $receiptResponse->getContent());
    }

    public function test_webhook_does_not_mark_a_payment_paid_when_amount_or_currency_does_not_match(): void
    {
        [$user, $project] = $this->clientProject();
        config(['services.mercadopago.access_token' => 'test-token']);
        config(['services.mercadopago.return_url' => 'http://localhost:8443']);
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'preference-456',
                'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=preference-456',
            ], 201),
        ]);
        $payment = $this->actingAs($user)
            ->postJson("/api/client/projects/{$project->id}/payments/card")
            ->assertCreated()
            ->json('payment');

        Http::fake([
            'https://api.mercadopago.com/v1/payments/987655' => Http::response([
                'external_reference' => $payment['reference'],
                'transaction_amount' => 1800,
                'currency_id' => 'USD',
                'status' => 'approved',
            ]),
        ]);
        $this->postJson('/api/payments/mercadopago/webhook', ['data' => ['id' => '987655']])
            ->assertOk();

        $this->assertDatabaseHas('project_payments', [
            'id' => $payment['id'],
            'status' => 'pendiente',
        ]);
    }

    private function clientProject(string $email = 'client@example.com'): array
    {
        $lead = Lead::create([
            'nombre' => 'Cliente',
            'email' => $email,
            'servicio' => 'Página web',
        ]);
        $user = User::factory()->create([
            'email' => $email,
            'is_client' => true,
        ]);
        $client = Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
        ]);
        $quote = $lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'descripcion' => 'Proyecto aceptado',
            'monto' => 1800,
            'estado' => 'aceptada',
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'cotizacion_id' => $quote->id,
            'nombre' => 'Proyecto privado',
        ]);

        return [$user, $project];
    }
}
