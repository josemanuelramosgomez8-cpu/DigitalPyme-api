<?php

namespace App\Support;

use App\Mail\PagoConfirmado;
use App\Models\ProjectPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PaymentReceiptNotifier
{
    public function send(ProjectPayment $payment): bool
    {
        $payment->loadMissing('project.client.user', 'project.cotizacion', 'milestone');
        $email = $payment->project?->client?->user?->email;

        if (blank($email)) {
            Log::warning('No se pudo enviar el recibo de pago: el cliente no tiene correo.', [
                'payment_id' => $payment->id,
            ]);

            return false;
        }

        try {
            Mail::to($email)->send(new PagoConfirmado($payment));

            return ! in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar el recibo de un pago DigitalPyme.', [
                'payment_id' => $payment->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
