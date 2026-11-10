<?php

namespace App\Support;

use App\Mail\StoreOrderConfirmation;
use App\Models\StoreOrder;
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

        try {
            Mail::to($email)->send(new StoreOrderConfirmation($order));

            return ! in_array(config('mail.default'), ['log', 'array'], true);
        } catch (TransportExceptionInterface $exception) {
            Log::error('No se pudo enviar el comprobante de un pedido de tienda.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
