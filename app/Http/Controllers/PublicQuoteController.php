<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\ServiceCartSession;
use App\Models\Servicio;
use App\Support\SolicitudNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicQuoteController extends Controller
{
    private const VISUAL_TEMPLATE_LABELS = [
        'sin-preferencia' => 'Sin preferencia',
        'moderna' => 'Moderna (sans serif y títulos expresivos)',
        'minimalista' => 'Minimalista (sans serif limpia)',
        'editorial' => 'Editorial (serif y sans serif)',
        'corporativa' => 'Corporativa (sans serif profesional)',
        'personalizada' => 'Personalizada (tipografía por definir)',
    ];

    public function store(Request $request, SolicitudNotifier $notifier)
    {
        if ($request->filled('website')) {
            throw ValidationException::withMessages([
                'form' => 'No se pudo validar el formulario. Actualiza la página e inténtalo nuevamente.',
            ]);
        }

        $datos = $request->validate([
            'nombre' => 'required|string|max:120',
            'apellido' => 'required|string|max:120',
            'empresa' => 'required|string|max:255',
            'pais' => 'required|string|max:80',
            'email' => 'required|email:rfc|max:255',
            'telefono' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'servicios' => 'required|array|min:1|max:20',
            'servicios.*' => 'required|string|max:255|distinct',
            'verification_token' => 'sometimes|nullable|string|size:64',
            'acepta_terminos' => 'required|accepted',
            'acepta_privacidad' => 'required|accepted',
            'metodo_pago_preferido' => ['sometimes', 'required', Rule::in(['undecided', 'card', 'bank_transfer', 'yape', 'plin', 'yape_plin', 'paypal'])],
            'website' => 'sometimes|nullable|string|max:255',
            'service_cart_token' => ['sometimes', 'nullable', 'uuid'],
            'service_cart_currency' => ['sometimes', 'nullable', Rule::in(['PEN', 'USD'])],
            'mensaje' => 'nullable|string|max:4000',
            'plantilla_visual' => ['sometimes', 'nullable', Rule::in(array_keys(self::VISUAL_TEMPLATE_LABELS))],
            'color_primario' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_acento' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'apellido.required' => 'El apellido es obligatorio.',
            'empresa.required' => 'El nombre de la empresa o negocio es obligatorio.',
            'pais.required' => 'El país es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'telefono.required' => 'El número de WhatsApp es obligatorio.',
            'telefono.regex' => 'Ingresa un número de WhatsApp válido con código de país (ej. +51999999999).',
            'servicios.required' => 'Selecciona al menos un servicio.',
            'servicios.array' => 'Los servicios deben enviarse como una lista.',
            'servicios.min' => 'Selecciona al menos un servicio.',
            'servicios.max' => 'Puedes seleccionar hasta 20 servicios.',
            'servicios.*.distinct' => 'No repitas servicios en la lista.',
            'verification_token.size' => 'El token de verificación no es válido.',
            'acepta_terminos.required' => 'Debes aceptar los términos de contratación para continuar.',
            'acepta_terminos.accepted' => 'Debes aceptar los términos de contratación para continuar.',
            'acepta_privacidad.required' => 'Debes aceptar la política de privacidad para continuar.',
            'acepta_privacidad.accepted' => 'Debes aceptar la política de privacidad para continuar.',
            'metodo_pago_preferido.in' => 'La modalidad de pago seleccionada no es válida.',
            'service_cart_token.uuid' => 'La referencia del carrito de servicios no es válida.',
            'service_cart_currency.in' => 'La moneda seleccionada no es válida.',
            'plantilla_visual.in' => 'La plantilla visual seleccionada no es válida.',
            'color_primario.regex' => 'El color primario debe tener formato hexadecimal (#RRGGBB).',
            'color_acento.regex' => 'El color de acento debe tener formato hexadecimal (#RRGGBB).',
        ]);

        $hasCustomColors = filled($datos['color_primario'] ?? null)
            || filled($datos['color_acento'] ?? null);
        if (($datos['plantilla_visual'] ?? null) !== 'personalizada' && $hasCustomColors) {
            throw ValidationException::withMessages([
                'plantilla_visual' => 'Selecciona la plantilla personalizada para enviar colores de marca.',
            ]);
        }

        $paymentPreference = $datos['metodo_pago_preferido'] ?? 'undecided';
        $paymentPreferenceLabel = match ($paymentPreference) {
            'card' => 'Tarjeta (Mercado Pago)',
            'bank_transfer' => 'Transferencia bancaria',
            'yape' => 'Yape (número o QR)',
            'plin' => 'Plin',
            'yape_plin' => 'Yape o Plin',
            'paypal' => 'PayPal (demo)',
            default => 'Aún no lo sé',
        };

        $servicios = Servicio::query()
            ->where('activo', true)
            ->whereIn('nombre', $datos['servicios'])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        if ($servicios->count() !== count($datos['servicios'])) {
            throw ValidationException::withMessages([
                'servicios' => 'Uno o más servicios ya no están disponibles. Actualiza la página e inténtalo nuevamente.',
            ]);
        }

        $montoCentimos = $servicios->sum(
            fn (Servicio $servicio) => $servicio->precio_desde === null
                ? 0
                : (int) round((float) $servicio->precio_desde * 100)
        );
        $monto = $montoCentimos / 100;
        $inversionEstimada = $servicios->contains(
            fn (Servicio $servicio) => $servicio->precio_desde !== null
        ) ? $monto : null;
        $serviciosDescripcion = $servicios
            ->map(function (Servicio $servicio) {
                $precio = $servicio->precio_desde === null
                    ? 'A cotizar'
                    : 'S/ '.number_format((float) $servicio->precio_desde, 2, '.', ',');

                return "- {$servicio->nombre}: {$precio}";
            })
            ->implode("\n");

        $consentimientoRegistrado = now()->toIso8601String();
        $registroComercial = "Registro comercial:\nPaís: {$datos['pais']}\nWhatsApp: {$datos['telefono']}\nMétodo de pago preferido (referencial): {$paymentPreferenceLabel}\nTérminos de contratación aceptados: sí\nAutorización de datos para atender esta solicitud: sí\nConsentimientos registrados: {$consentimientoRegistrado}";
        $descripcion = "Servicios seleccionados:\n{$serviciosDescripcion}";
        if (filled($datos['mensaje'] ?? null)) {
            $descripcion .= "\n\nDetalles del proyecto:\n".$datos['mensaje'];
        }
        $visualSummary = null;
        if (filled($datos['plantilla_visual'] ?? null)) {
            $visualDetails = [
                'Plantilla visual seleccionada: '.self::VISUAL_TEMPLATE_LABELS[$datos['plantilla_visual']],
            ];
            if ($datos['plantilla_visual'] === 'personalizada') {
                if (filled($datos['color_primario'] ?? null)) {
                    $visualDetails[] = 'Color principal de referencia: '.$datos['color_primario'];
                }
                if (filled($datos['color_acento'] ?? null)) {
                    $visualDetails[] = 'Color de acento de referencia: '.$datos['color_acento'];
                }
            }
            $visualSummary = "Preferencias visuales:\n".implode("\n", $visualDetails);
            $descripcion .= "\n\n".$visualSummary;
        }
        $mensajeLead = trim(implode("\n\n", array_filter([
            $registroComercial,
            $datos['mensaje'] ?? null,
            $visualSummary,
        ])));

        $requestIp = (string) $request->ip();
        [$lead, $cotizacion] = DB::transaction(function () use ($datos, $descripcion, $mensajeLead, $monto, $inversionEstimada, $servicios, $requestIp) {
            $cartSession = null;
            if (filled($datos['service_cart_token'] ?? null)) {
                $cartSession = ServiceCartSession::query()
                    ->where('token', $datos['service_cart_token'])
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $cartSession
                    || $cartSession->status !== 'active'
                    || empty($cartSession->items)
                ) {
                    throw ValidationException::withMessages([
                        'service_cart_token' => 'La selección de servicios ya no está disponible.',
                    ]);
                }

                $cartCurrency = $datos['service_cart_currency'] ?? $cartSession->currency;
                if (
                    $cartSession->currency !== $cartCurrency
                    || collect($cartSession->items)->pluck('slug')->sort()->values()->all()
                        !== $servicios->pluck('slug')->sort()->values()->all()
                ) {
                    throw ValidationException::withMessages([
                        'servicios' => 'Los servicios seleccionados no coinciden con el carrito guardado.',
                    ]);
                }
            }

            if (
                filled($datos['verification_token'] ?? null)
                && ! PublicQuoteVerificationController::consumeVerification(
                    $datos['verification_token'],
                    $datos['email'],
                    $requestIp,
                )
            ) {
                throw ValidationException::withMessages([
                    'verification_token' => 'Verifica tu correo nuevamente antes de enviar la solicitud.',
                ]);
            }

            $lead = Lead::create([
                'nombre' => trim($datos['nombre'].' '.$datos['apellido']),
                'empresa' => $datos['empresa'] ?? null,
                'email' => $datos['email'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'servicio' => 'Propuesta personalizada',
                'mensaje' => $mensajeLead,
            ]);

            $cotizacion = $lead->cotizaciones()->create([
                'servicio' => 'Proyecto personalizado',
                'descripcion' => $descripcion,
                'monto' => $monto,
            ]);

            $lead->update(['inversion_estimada' => $inversionEstimada]);

            if ($cartSession) {
                $cartSession->update([
                    'status' => 'converted',
                    'converted_at' => now(),
                    'cotizacion_id' => $cotizacion->id,
                    'customer_name' => null,
                    'email' => null,
                    'phone' => null,
                    'communications_consented_at' => null,
                    'updated_at' => now(),
                ]);
            }

            return [$lead, $cotizacion];
        });

        $notificacion = $notifier->notify($lead, $cotizacion);

        return response()->json([
            'mensaje' => 'La solicitud de propuesta fue recibida correctamente.',
            'cotizacion_id' => $cotizacion->id,
            'inversion_estimada' => $inversionEstimada,
            'metodo_pago_preferido' => $paymentPreference,
            'incluye_servicios_a_cotizar' => $servicios->contains(
                fn (Servicio $servicio) => $servicio->precio_desde === null
            ),
            ...$notificacion,
        ], 201);
    }
}
