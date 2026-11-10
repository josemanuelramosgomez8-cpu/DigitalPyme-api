<?php

namespace App\Http\Controllers;

use App\Mail\CodigoVerificacionCotizacion;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicQuoteVerificationController extends Controller
{
    private const CHALLENGE_TTL_SECONDS = 600;
    private const VERIFIED_TTL_SECONDS = 900;
    private const MAX_CODE_ATTEMPTS = 5;

    public function sendCode(Request $request)
    {
        $this->rejectAutomatedSubmission($request);

        $datos = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($datos['email']));
        if (config('mail.default') === 'log') {
            return response()->json([
                'message' => 'La verificación por correo no está habilitada. Configura un proveedor de correo e inténtalo nuevamente.',
            ], 503);
        }

        $ip = (string) $request->ip();
        $emailIpHash = hash('sha256', $email.'|'.$ip);
        $sendKey = 'public-quote:verification:send:'.$emailIpHash;
        $cooldownKey = 'public-quote:verification:cooldown:'.$emailIpHash;

        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return response()->json([
                'message' => 'Se alcanzó el límite de códigos para esta dirección. Inténtalo más tarde.',
            ], 429);
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return response()->json([
                'message' => 'Espera un minuto antes de solicitar otro código.',
            ], 429);
        }

        RateLimiter::hit($sendKey, 3600);
        RateLimiter::hit($cooldownKey, 60);

        $challengeKey = 'public-quote:verification:challenge:'.$emailIpHash;
        $code = (string) random_int(100000, 999999);
        Cache::put($challengeKey, [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->timestamp,
        ], now()->addSeconds(self::CHALLENGE_TTL_SECONDS));

        try {
            Mail::to($email)->send(new CodigoVerificacionCotizacion($code));
        } catch (Throwable $exception) {
            Cache::forget($challengeKey);
            Log::error('No se pudo enviar el código de verificación de cotización.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'No pudimos enviar el código. Inténtalo nuevamente más tarde.',
            ], 503);
        }

        return response()->json([
            'mensaje' => 'Enviamos un código de seis dígitos a tu correo. Vence en 10 minutos.',
        ]);
    }

    public function verifyCode(Request $request)
    {
        $this->rejectAutomatedSubmission($request);

        $datos = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'digits:6'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($datos['email']));
        $ip = (string) $request->ip();
        $emailIpHash = hash('sha256', $email.'|'.$ip);
        $attemptKey = 'public-quote:verification:attempts:'.$emailIpHash;

        if (RateLimiter::tooManyAttempts($attemptKey, self::MAX_CODE_ATTEMPTS)) {
            return response()->json([
                'message' => 'Se alcanzó el límite de intentos. Solicita un nuevo código más tarde.',
            ], 429);
        }

        $challengeKey = 'public-quote:verification:challenge:'.$emailIpHash;
        $challenge = Cache::get($challengeKey);
        if (!is_array($challenge) || !isset($challenge['code_hash'], $challenge['expires_at'])) {
            throw ValidationException::withMessages([
                'code' => 'El código venció o no existe. Solicita uno nuevo.',
            ]);
        }

        RateLimiter::hit($attemptKey, self::CHALLENGE_TTL_SECONDS);

        if (!Hash::check($datos['code'], $challenge['code_hash'])) {
            $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
            $remainingSeconds = max(1, (int) $challenge['expires_at'] - now()->timestamp);

            if ($challenge['attempts'] >= self::MAX_CODE_ATTEMPTS) {
                Cache::forget($challengeKey);
            } else {
                Cache::put($challengeKey, $challenge, now()->addSeconds($remainingSeconds));
            }

            throw ValidationException::withMessages([
                'code' => 'El código ingresado no es válido.',
            ]);
        }

        Cache::forget($challengeKey);
        RateLimiter::clear($attemptKey);
        $verificationToken = Str::random(64);
        Cache::put($this->verifiedTokenKey($verificationToken), [
            'email_hash' => hash('sha256', $email),
            'ip_hash' => hash('sha256', $ip),
        ], now()->addSeconds(self::VERIFIED_TTL_SECONDS));

        return response()->json([
            'mensaje' => 'Correo verificado correctamente.',
            'verification_token' => $verificationToken,
        ]);
    }

    public static function consumeVerification(string $token, string $email, string $ip): bool
    {
        $key = 'public-quote:verification:verified:'.hash('sha256', $token);
        try {
            return Cache::lock($key.':consume-lock', 5)->block(2, function () use ($key, $email, $ip): bool {
                $verification = Cache::get($key);
                if (!is_array($verification)
                    || !isset($verification['email_hash'], $verification['ip_hash'])
                    || !hash_equals($verification['email_hash'], hash('sha256', Str::lower(trim($email))))
                    || !hash_equals($verification['ip_hash'], hash('sha256', $ip))) {
                    return false;
                }

                Cache::forget($key);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function verifiedTokenKey(string $token): string
    {
        return 'public-quote:verification:verified:'.hash('sha256', $token);
    }

    private function rejectAutomatedSubmission(Request $request): void
    {
        if ($request->filled('website')) {
            throw ValidationException::withMessages([
                'form' => 'No se pudo validar el formulario. Actualiza la página e inténtalo nuevamente.',
            ]);
        }
    }
}
