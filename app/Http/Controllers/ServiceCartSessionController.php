<?php

namespace App\Http\Controllers;

use App\Models\ServiceCartSession;
use App\Models\Servicio;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceCartSessionController extends Controller
{
    public function upsert(Request $request, string $token)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:20'],
            'items.*.slug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:255', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'currency' => ['required', Rule::in(['PEN', 'USD'])],
            'communications_consent' => ['sometimes', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
        ]);

        if (
            ($data['communications_consent'] ?? false)
            && blank($data['email'] ?? null)
            && blank($data['phone'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'email' => 'Ingresa un correo o WhatsApp para autorizar el recordatorio.',
            ]);
        }

        $slugs = collect($data['items'])->pluck('slug')->unique()->values();
        $services = Servicio::query()
            ->where('activo', true)
            ->whereIn('slug', $slugs)
            ->get(['id', 'nombre', 'slug'])
            ->keyBy('slug');

        if ($services->count() !== $slugs->count()) {
            throw ValidationException::withMessages([
                'items' => 'Uno o más servicios ya no están disponibles.',
            ]);
        }

        $canonicalItems = collect($data['items'])->map(function (array $item) use ($services) {
            $service = $services->get($item['slug']);

            return [
                'slug' => $service->slug,
                'name' => $service->nombre,
                'quantity' => $item['quantity'],
            ];
        })->values()->all();

        $session = DB::transaction(function () use ($data, $canonicalItems, $token) {
            $session = ServiceCartSession::query()->where('token', $token)->lockForUpdate()->first()
                ?? new ServiceCartSession(['token' => $token]);

            if ($data['items'] === []) {
                if ($session->exists && $session->status !== 'converted') {
                    $session->update([
                        'items' => [],
                        'customer_name' => null,
                        'email' => null,
                        'phone' => null,
                        'communications_consented_at' => null,
                        'status' => 'cleared',
                        'last_activity_at' => now(),
                    ]);
                }

                return $session->exists ? $session->refresh() : null;
            }

            if (
                $session->exists
                && $session->status === 'converted'
                && $session->currency === $data['currency']
                && $session->items === $canonicalItems
            ) {
                return $session;
            }

            $updates = [
                'items' => $canonicalItems,
                'currency' => $data['currency'],
                'status' => 'active',
                'last_activity_at' => now(),
                'converted_at' => null,
                'cotizacion_id' => null,
                'reminders_sent' => 0,
                'last_reminder_at' => null,
            ];

            if (array_key_exists('communications_consent', $data)) {
                if ($data['communications_consent']) {
                    $updates += [
                        'customer_name' => $data['customer_name'] ?? null,
                        'email' => $data['email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'communications_consented_at' => $session->communications_consented_at ?? now(),
                    ];
                } else {
                    $updates += [
                        'customer_name' => null,
                        'email' => null,
                        'phone' => null,
                        'communications_consented_at' => null,
                    ];
                }
            }

            $session->fill($updates)->save();

            return $session->refresh();
        });

        return response()->json([
            'token' => $token,
            'status' => $session?->status ?? 'cleared',
        ]);
    }

    public function restore(Request $request, string $token)
    {
        $session = DB::transaction(function () use ($token) {
            $session = ServiceCartSession::query()->where('token', $token)->lockForUpdate()->firstOrFail();
            abort_if(
                $session->status === 'converted' || $session->status === 'cleared' || empty($session->items),
                410,
                'Esta selección ya no está disponible para restaurarse.',
            );

            $session->update([
                'status' => 'active',
                'last_activity_at' => now(),
            ]);

            return $session->refresh();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'items' => $session->items,
                'currency' => $session->currency,
            ]);
        }

        $restorePath = $request->getPathInfo().'?'.$request->getQueryString();

        return redirect(
            rtrim(config('services.digitalpyme.frontend_url'), '/')
                .'/carrito-servicios?service_restore='.rawurlencode($restorePath),
        );
    }

    public function unsubscribe(Request $request, string $token)
    {
        ServiceCartSession::query()
            ->where('token', $token)
            ->update([
                'customer_name' => null,
                'email' => null,
                'phone' => null,
                'communications_consented_at' => null,
                'updated_at' => now(),
            ]);

        return redirect(
            rtrim(config('services.digitalpyme.frontend_url'), '/')
                .'/carrito-servicios?service_reminders=off',
        );
    }

    public function adminIndex()
    {
        $sessions = ServiceCartSession::query()
            ->where('status', 'active')
            ->where('last_activity_at', '<=', now()->subHour())
            ->latest('last_activity_at')
            ->limit(100)
            ->get()
            ->map(fn (ServiceCartSession $session) => [
                'id' => $session->id,
                'items' => $session->items,
                'currency' => $session->currency,
                'customer_name' => $session->customer_name,
                'email' => $session->email,
                'phone' => $session->phone,
                'communications_consented' => $session->communications_consented_at !== null,
                'last_activity_at' => $session->last_activity_at,
                'reminders_sent' => $session->reminders_sent,
                'restore_url' => $this->restoreFrontendUrl($session),
            ]);

        return response()->json($sessions);
    }

    public function sendReminder(
        ServiceCartSession $serviceCartSession,
        CustomerLifecycleNotifier $notifier,
    ) {
        $reminder = DB::transaction(function () use ($serviceCartSession) {
            $session = ServiceCartSession::query()
                ->lockForUpdate()
                ->findOrFail($serviceCartSession->id);

            if (
                $session->status !== 'active'
                || $session->last_activity_at->gt(now()->subHour())
                || $session->communications_consented_at === null
                || blank($session->email)
                || $session->reminders_sent >= 1
                || empty($session->items)
            ) {
                throw ValidationException::withMessages([
                    'session' => 'El recordatorio requiere una selección abandonada, correo y consentimiento vigentes.',
                ]);
            }

            $session->update([
                'reminders_sent' => 1,
                'last_reminder_at' => now(),
            ]);

            return [
                'id' => $session->id,
                'token' => $session->token,
                'email' => $session->email,
                'customer_name' => $session->customer_name,
                'currency' => $session->currency,
                'items' => $session->items,
            ];
        });

        $restoreUrl = $this->restoreFrontendUrl(
            ServiceCartSession::query()->findOrFail($reminder['id']),
        );
        $unsubscribeUrl = URL::signedRoute(
            'service-carts.unsubscribe',
            ['token' => $reminder['token']],
            now()->addDays(30),
        );
        $itemDetails = collect($reminder['items'])
            ->map(fn (array $item) => "{$item['name']} × {$item['quantity']}")
            ->all();

        $notifier->notify(
            eventType: 'service_cart_abandoned_reminder',
            email: $reminder['email'],
            subject: 'Puedes retomar tu cotización en DigitalPyme',
            intro: 'Guardamos los servicios que seleccionaste. Si aún te interesa, puedes retomarlos y completar tu solicitud de cotización.',
            details: [
                'Servicios seleccionados: '.implode(', ', $itemDetails),
                'Moneda de referencia: '.$reminder['currency'],
                'El envío de la solicitud no genera un cobro.',
            ],
            actionLabel: 'Retomar mi cotización',
            actionUrl: $restoreUrl,
            unsubscribeUrl: $unsubscribeUrl,
            resourceType: 'service_cart_session',
            resourceId: $reminder['id'],
            sendDatabase: false,
        );

        return response()->json([
            'mensaje' => 'El recordatorio por correo quedó en cola.',
            'reminders_sent' => 1,
        ]);
    }

    private function restoreFrontendUrl(ServiceCartSession $session): string
    {
        $restorePath = URL::signedRoute(
            'service-carts.restore',
            ['token' => $session->token],
            now()->addDays(7),
            false,
        );

        return rtrim(config('services.digitalpyme.frontend_url'), '/')
            .'/carrito-servicios?service_restore='.rawurlencode($restorePath);
    }
}
