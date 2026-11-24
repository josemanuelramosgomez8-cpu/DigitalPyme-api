<?php

namespace Tests\Feature;

use App\Mail\PagoConfirmado;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\SiteSetting;
use App\Models\StoreOrder;
use App\Models\User;
use App\Support\PaymentConfirmationLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_project_payment_has_a_signed_confirmation_and_logged_email_resend(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        [$user, $project] = $this->clientProject();
        $payment = $project->payments()->create([
            'reference' => 'pay-reference-123',
            'method' => 'yape_plin',
            'amount' => 1800,
            'currency' => 'PEN',
            'status' => 'pagado',
            'paid_at' => now(),
        ]);
        SiteSetting::savePublicSettings([
            'contact' => ['whatsapp_phone' => '+51999888777'],
        ]);
        $links = app(PaymentConfirmationLinks::class)->forProject($payment);

        $response = $this->get($links['show']);
        $response
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Pago recibido exitosamente')
            ->assertSee('S/ 1,800.00')
            ->assertSee('DNI')
            ->assertSee('Yape / Plin')
            ->assertSee('Este es un comprobante informativo de pago')
            ->assertSee('https://wa.me/51999888777', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $pdf = $this->get($links['pdf'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $emailResponse = $this->post($links['email']);
        $emailResponse
            ->assertRedirect()
            ->assertSessionHas('receipt_email_status', 'sent');
        $this->get($emailResponse->headers->get('Location'))->assertOk();

        Mail::assertSent(PagoConfirmado::class, fn (PagoConfirmado $mail) => $mail->payment->is($payment));
        Mail::assertSent(PagoConfirmado::class, fn (PagoConfirmado $mail) => str_contains(
            $mail->render(),
            'Consultar o descargar el comprobante informativo',
        ));
        $this->assertDatabaseHas('notification_logs', [
            'event_type' => 'project_payment_receipt',
            'recipient_email' => $user->email,
            'channel' => 'mail',
            'status' => 'sent',
        ]);
    }

    public function test_unsigned_confirmation_links_are_rejected_and_pending_payments_are_not_presented_as_paid(): void
    {
        [, $project] = $this->clientProject();
        $payment = $project->payments()->create([
            'reference' => 'pending-reference-123',
            'method' => 'bank_transfer',
            'amount' => 1800,
            'currency' => 'PEN',
            'status' => 'pendiente',
        ]);

        $this->get("/pagos/proyectos/{$payment->id}/comprobante")
            ->assertForbidden();

        $url = app(PaymentConfirmationLinks::class)->forProject($payment)['show'];
        $this->get($url)
            ->assertOk()
            ->assertSee('Pago pendiente')
            ->assertDontSee('Descargar PDF')
            ->assertDontSee('Enviar por correo');

        $this->get(app(PaymentConfirmationLinks::class)->forProject($payment)['pdf'])
            ->assertNotFound();
    }

    public function test_local_log_mailer_does_not_report_a_receipt_email_as_sent(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        [$user, $project] = $this->clientProject();
        $payment = $project->payments()->create([
            'reference' => 'log-mail-reference',
            'method' => 'bank_transfer',
            'amount' => 1800,
            'currency' => 'PEN',
            'status' => 'pagado',
            'paid_at' => now(),
        ]);
        $links = app(PaymentConfirmationLinks::class)->forProject($payment);

        $emailResponse = $this->post($links['email']);
        $emailResponse
            ->assertRedirect()
            ->assertSessionHas('receipt_email_status', 'failed');

        $this->assertDatabaseHas('notification_logs', [
            'event_type' => 'project_payment_receipt',
            'recipient_email' => $user->email,
            'channel' => 'mail',
            'status' => 'skipped',
        ]);
        Mail::assertSent(PagoConfirmado::class);
    }

    public function test_store_order_confirmation_uses_the_order_currency_and_saved_tax_totals(): void
    {
        $user = User::factory()->create(['email' => 'store-client@example.com']);
        $client = Client::create([
            'user_id' => $user->id,
            'nombre' => 'Cliente Tienda',
            'telefono' => '+51999888777',
            'tipo_documento' => 'ruc',
            'numero_documento' => '20123456789',
            'tipo_comprobante' => 'factura',
            'direccion_entrega' => 'Av. Principal 123',
            'ubicacion_entrega' => 'Tumbes',
        ]);
        $order = StoreOrder::create([
            'client_id' => $client->id,
            'nombre_cliente' => 'Cliente Tienda',
            'email_cliente' => $user->email,
            'telefono_cliente' => '+51999888777',
            'tipo_comprobante' => 'factura',
            'tipo_documento' => 'ruc',
            'numero_documento' => '20123456789',
            'empresa_facturacion' => 'Comercial Demo SAC',
            'estado' => 'recibido',
            'payment_method' => 'card',
            'payment_status' => 'pagado',
            'provider_reference' => 'store-reference-123',
            'paid_at' => now(),
            'display_currency' => 'USD',
            'display_exchange_rate' => '3.750000',
            'metodo_envio' => 'recojo',
            'ubicacion_entrega' => 'Tumbes',
            'direccion_entrega' => 'Av. Principal 123',
            'subtotal' => '100.00',
            'costo_envio' => '10.00',
            'discount_amount' => '0.00',
            'igv_rate' => '18.00',
            'igv_amount' => '19.80',
            'total' => '129.80',
        ]);
        $order->items()->create([
            'sku' => 'DIGI-001',
            'nombre' => 'Servicio digital',
            'cantidad' => 2,
            'precio_unitario' => '50.00',
            'subtotal' => '100.00',
        ]);
        $url = app(PaymentConfirmationLinks::class)->forOrder($order)['show'];

        $this->get($url)
            ->assertOk()
            ->assertSee('$ 34.61')
            ->assertSee('USD')
            ->assertSee('RUC')
            ->assertSee('Comercial Demo SAC')
            ->assertSee('IGV (18%)')
            ->assertSee('S/ 129.80')
            ->assertSee('no es un comprobante tributario emitido por SUNAT');

        $pdf = $this->get(app(PaymentConfirmationLinks::class)->forOrder($order)['pdf'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    private function clientProject(): array
    {
        $lead = Lead::create([
            'nombre' => 'Cliente',
            'email' => 'receipt-client@example.com',
            'servicio' => 'Página web',
        ]);
        $user = User::factory()->create([
            'email' => 'receipt-client@example.com',
            'is_client' => true,
        ]);
        $client = Client::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'nombre' => $lead->nombre,
            'tipo_documento' => 'dni',
            'numero_documento' => '12345678',
            'telefono' => '+51999111222',
        ]);
        $quote = $lead->cotizaciones()->create([
            'servicio' => 'Página web',
            'descripcion' => 'Desarrollo de sitio web',
            'monto' => 1800,
            'estado' => 'aceptada',
        ]);
        $project = Project::create([
            'client_id' => $client->id,
            'cotizacion_id' => $quote->id,
            'nombre' => 'Proyecto web',
        ]);

        return [$user, $project];
    }
}
