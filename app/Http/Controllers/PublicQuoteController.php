<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Servicio;
use App\Support\SolicitudNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicQuoteController extends Controller
{
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
            'verification_token' => 'required|string|size:64',
            'acepta_terminos' => 'required|accepted',
            'acepta_privacidad' => 'required|accepted',
            'metodo_pago_preferido' => ['sometimes', 'required', Rule::in(['undecided', 'card', 'bank_transfer', 'yape', 'plin', 'yape_plin', 'paypal'])],
            'website' => 'sometimes|nullable|string|max:255',
            'mensaje' => 'nullable|string|max:4000',
        ]);

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

        if (! PublicQuoteVerificationController::consumeVerification(
            $datos['verification_token'],
            $datos['email'],
            (string) $request->ip(),
        )) {
            throw ValidationException::withMessages([
                'verification_token' => 'Verifica tu correo nuevamente antes de enviar la solicitud.',
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
        $mensajeLead = trim(implode("\n\n", array_filter([
            $registroComercial,
            $datos['mensaje'] ?? null,
        ])));

        [$lead, $cotizacion] = DB::transaction(function () use ($datos, $descripcion, $mensajeLead, $monto, $inversionEstimada) {
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
