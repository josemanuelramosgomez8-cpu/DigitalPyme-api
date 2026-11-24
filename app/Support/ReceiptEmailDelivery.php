<?php

namespace App\Support;

use App\Models\NotificationLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class ReceiptEmailDelivery
{
    public function send(Mailable $mail, string $recipient, string $eventType, array $payload): bool
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('No se pudo enviar un comprobante: el correo guardado no es válido.', [
                'event_type' => $eventType,
            ]);

            return false;
        }

        $log = NotificationLog::create([
            'event_type' => $eventType,
            'recipient_email' => $recipient,
            'channel' => 'mail',
            'status' => 'queued',
            'payload' => $payload,
        ]);

        $localTransport = in_array(config('mail.default'), ['log', 'array'], true);

        try {
            Mail::to($recipient)->send($mail);
        } catch (TransportExceptionInterface|InvalidArgumentException $exception) {
            $log->update([
                'status' => 'failed',
                'failure_message' => 'No se pudo entregar el correo de confirmación.',
            ]);
            Log::error('Falló el envío de un comprobante por correo.', [
                'event_type' => $eventType,
                'notification_log_id' => $log->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        if ($localTransport) {
            $log->update([
                'status' => 'skipped',
                'failure_message' => 'El transporte de correo solo está configurado para pruebas o registro local.',
            ]);

            return false;
        }

        $log->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return true;
    }
}
