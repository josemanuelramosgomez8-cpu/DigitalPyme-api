<?php

namespace Tests\Feature;

use App\Mail\CodigoVerificacionCotizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicQuoteVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'mail.default' => 'smtp',
        ]);
        Cache::flush();
        RateLimiter::clear('public-quote:verification:send:'.hash('sha256', 'cliente@example.com|127.0.0.1'));
        Mail::fake();
    }

    public function test_email_code_is_sent_without_exposing_it_and_can_be_verified_once(): void
    {
        $this->postJson('/api/propuestas/codigo', [
            'email' => 'Cliente@Example.com',
            'website' => '',
        ])->assertOk()
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('verification_token');

        $code = null;
        Mail::assertSent(CodigoVerificacionCotizacion::class, function (CodigoVerificacionCotizacion $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('cliente@example.com');
        });

        $this->postJson('/api/propuestas/verificar-codigo', [
            'email' => 'cliente@example.com',
            'code' => '000000',
            'website' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $verified = $this->postJson('/api/propuestas/verificar-codigo', [
            'email' => 'cliente@example.com',
            'code' => $code,
            'website' => '',
        ])->assertOk()
            ->assertJsonPath('mensaje', 'Correo verificado correctamente.');

        $this->assertIsString($verified->json('verification_token'));

        $this->postJson('/api/propuestas/verificar-codigo', [
            'email' => 'cliente@example.com',
            'code' => $code,
            'website' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_honeypot_rejects_automated_code_requests(): void
    {
        $this->postJson('/api/propuestas/codigo', [
            'email' => 'cliente@example.com',
            'website' => 'https://spam.example',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['form']);

        Mail::assertNothingSent();
    }

    public function test_email_verification_is_disabled_when_only_the_log_mailer_is_configured(): void
    {
        config(['mail.default' => 'log']);

        $this->postJson('/api/propuestas/codigo', [
            'email' => 'cliente@example.com',
            'website' => '',
        ])->assertServiceUnavailable()
            ->assertJsonPath('message', 'La verificación por correo no está habilitada. Configura un proveedor de correo e inténtalo nuevamente.');

        Mail::assertNothingSent();
    }

    public function test_code_requests_are_rate_limited_per_email_and_ip(): void
    {
        $this->postJson('/api/propuestas/codigo', [
            'email' => 'cliente@example.com',
            'website' => '',
        ])->assertOk();

        $this->postJson('/api/propuestas/codigo', [
            'email' => 'cliente@example.com',
            'website' => '',
        ])->assertTooManyRequests();

        Mail::assertSent(CodigoVerificacionCotizacion::class, 1);
    }
}
