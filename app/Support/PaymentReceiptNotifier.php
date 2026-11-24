<?php

namespace App\Support;

use App\Mail\PagoConfirmado;
use App\Models\ProjectPayment;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Support\Facades\Log;

class PaymentReceiptNotifier
{
    public function send(ProjectPayment $payment, bool $notifyCustomer = true): bool
    {
        $payment->loadMissing('project.client.user', 'project.cotizacion', 'milestone');
        $email = $payment->project?->client?->user?->email;

        if (blank($email)) {
            Log::warning('No se pudo enviar el recibo de pago: el cliente no tiene correo.', [
                'payment_id' => $payment->id,
            ]);

            return false;
        }

        $emailSent = app(ReceiptEmailDelivery::class)->send(
            new PagoConfirmado($payment),
            $email,
            'project_payment_receipt',
            ['payment_id' => $payment->id, 'reference' => $payment->reference],
        );

        if ($notifyCustomer) {
            app(CustomerLifecycleNotifier::class)->notify(
                eventType: 'project_payment_confirmed',
                email: $email,
                subject: 'Pago confirmado',
                intro: 'Confirmamos el pago de tu proyecto. El comprobante informativo está disponible en el portal.',
                details: [
                    'Proyecto: '.$payment->project->nombre,
                    'Monto: '.$payment->currency.' '.number_format((float) $payment->amount, 2, '.', ','),
                ],
                userId: $payment->project->client?->user_id,
                actionLabel: 'Ver mis pagos',
                actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/portal?tab=pagos',
                resourceType: 'project_payment',
                resourceId: $payment->id,
                sendEmail: false,
                sendDatabase: true,
            );
        }

        return $emailSent;
    }
}
