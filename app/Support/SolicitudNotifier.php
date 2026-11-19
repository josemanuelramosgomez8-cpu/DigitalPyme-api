<?php

namespace App\Support;

use App\Mail\AgendaReservada;
use App\Mail\SolicitudRecibida;
use App\Models\AgendaReservation;
use App\Models\Cotizacion;
use App\Models\Lead;
use App\Models\SiteSetting;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class SolicitudNotifier
{
    public function notifyReservation(AgendaReservation $reserva): array
    {
        $emailEnviado = false;
        $destinatario = config('services.digitalpyme.solicitudes_email')
            ?: config('mail.from.address');

        try {
            Mail::to($destinatario)->send(new AgendaReservada($reserva));
            $emailEnviado = !in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar el correo de una reserva de agenda DigitalPyme.', [
                'reservation_id' => $reserva->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $telefono = preg_replace('/\D+/', '', (string) SiteSetting::publicSettings()['contact']['whatsapp_phone']);
        $slot = $reserva->slot;
        $texto = "Nueva llamada agendada con {$reserva->nombre} para {$slot->date->format('d/m/Y')} a las ".substr($slot->time, 0, 5).'.';

        return [
            'email_enviado' => $emailEnviado,
            'whatsapp_url' => $telefono
                ? 'https://wa.me/'.$telefono.'?text='.rawurlencode($texto)
                : null,
        ];
    }

    public function notify(Lead $lead, ?Cotizacion $cotizacion = null): array
    {
        $emailEnviado = false;
        $destinatario = config('services.digitalpyme.solicitudes_email')
            ?: config('mail.from.address');

        try {
            Mail::to($destinatario)->send(new SolicitudRecibida($lead, $cotizacion));
            $emailEnviado = !in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar el correo de una solicitud DigitalPyme.', [
                'lead_id' => $lead->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        if (filled($lead->email)) {
            app(CustomerLifecycleNotifier::class)->notify(
                eventType: 'quote_request_received',
                email: $lead->email,
                subject: 'Recibimos tu solicitud de DigitalPyme',
                intro: 'Gracias por contarnos sobre tu proyecto. El equipo revisará la información y se pondrá en contacto contigo.',
                details: array_values(array_filter([
                    'Solicitud: '.($cotizacion?->servicio ?? $lead->servicio),
                    $cotizacion ? 'Referencia de solicitud: #'.$cotizacion->id : null,
                ])),
                userId: $lead->client?->user_id,
                actionLabel: 'Conocer nuestros servicios',
                actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/',
            );
        }

        $telefono = preg_replace('/\D+/', '', (string) SiteSetting::publicSettings()['contact']['whatsapp_phone']);
        $importe = $cotizacion
            ? ($lead->inversion_estimada !== null
                ? 'Subtotal referencial de servicios con precio publicado: S/ '.number_format((float) $lead->inversion_estimada, 2, '.', ',').'.'
                : 'Inversión pendiente de cotizar.')
            : null;
        $texto = $cotizacion
            ? "Nueva propuesta solicitada por {$lead->nombre} ({$lead->empresa}): {$cotizacion->servicio}. {$importe}"
            : "Nueva solicitud de {$lead->nombre} ({$lead->empresa}) para {$lead->servicio}.";

        return [
            'email_enviado' => $emailEnviado,
            'whatsapp_url' => $telefono
                ? 'https://wa.me/'.$telefono.'?text='.rawurlencode($texto)
                : null,
        ];
    }
}
