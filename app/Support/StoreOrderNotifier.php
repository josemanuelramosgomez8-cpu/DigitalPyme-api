<?php

namespace App\Support;

use App\Mail\StoreOrderConfirmation;
use App\Models\StoreOrder;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class StoreOrderNotifier
{
    public function send(StoreOrder $order): bool
    {
        $order->loadMissing('items', 'client.user');
        $email = $order->email_cliente ?: $order->client?->user?->email;

        if (blank($email)) {
            Log::warning('No se pudo enviar la confirmación: el pedido no tiene correo de cliente.', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        $emailSent = false;
        try {
            Mail::to($email)->send(new StoreOrderConfirmation($order));
            $emailSent = ! in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar el comprobante de un pedido de tienda.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $paid = $order->payment_status === 'pagado';
        $details = [
            "Pedido #{$order->id}",
            'Total: S/ '.number_format((float) $order->total, 2, '.', ','),
        ];
        if (! $paid) {
            $details[] = 'El pago debe completarse antes del '.($order->payment_due_at?->format('d/m/Y H:i') ?? 'plazo indicado en el portal');
            $details[] = match ($order->payment_method) {
                'bank_transfer' => 'Realiza la transferencia a la cuenta bancaria indicada en tu portal y adjunta el comprobante.',
                'yape_plin' => 'Realiza el pago por Yape/Plin al número '.(config('services.yape_plin.phone') ?: 'mostrado en tu portal').' y adjunta el comprobante.',
                default => 'Inicia el pago con tarjeta desde el portal de cliente.',
            };
        } else {
            $details[] = 'Pago confirmado: '.($order->paid_at?->format('d/m/Y H:i') ?? 'sí');
        }
        app(CustomerLifecycleNotifier::class)->notify(
            eventType: $paid ? 'store_order_payment_confirmed' : 'store_order_created',
            email: $email,
            subject: $paid ? "Pago confirmado · Pedido #{$order->id}" : "Pedido recibido · #{$order->id}",
            intro: $paid
                ? 'Confirmamos tu pago. Adjuntamos el resumen informativo de tu compra.'
                : 'Recibimos tu pedido y el pago está pendiente.',
            details: $details,
            userId: $order->client?->user_id,
            actionLabel: 'Ver mi pedido',
            actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/portal?tab=pedidos',
            resourceType: 'store_order',
            resourceId: $order->id,
            sendEmail: false,
            sendDatabase: true,
        );

        return $emailSent;
    }
}
