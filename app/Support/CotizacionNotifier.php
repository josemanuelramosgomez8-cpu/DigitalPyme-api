<?php

namespace App\Support;

use App\Mail\CotizacionEnviada;
use App\Models\Cotizacion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class CotizacionNotifier
{
    public function send(Cotizacion $cotizacion): bool
    {
        $cotizacion->loadMissing('lead');
        $email = $cotizacion->lead?->email;

        if (blank($email)) {
            Log::warning('No se pudo enviar una cotización: el lead no tiene correo.', [
                'cotizacion_id' => $cotizacion->id,
            ]);

            return false;
        }

        try {
            Mail::to($email)->send(new CotizacionEnviada($cotizacion));

            return ! in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar por correo una cotización DigitalPyme.', [
                'cotizacion_id' => $cotizacion->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
