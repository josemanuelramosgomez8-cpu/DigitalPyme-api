<?php

namespace App\Support;

use App\Mail\StoreOrderConfirmation;
use App\Models\SiteSetting;
use App\Models\StoreOrder;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Support\Facades\Log;

class StoreOrderNotifier
{
    public function send(StoreOrder $order, bool $notifyCustomer = true): bool
    {
        $order->loadMissing('items', 'client.user');
        $email = $order->email_cliente ?: $order->client?->user?->email;

        if (blank($email)) {
            Log::warning('No se pudo enviar la confirmación: el pedido no tiene correo de cliente.', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        $emailSent = app(ReceiptEmailDelivery::class)->send(
            new StoreOrderConfirmation($order),
            $email,
            $order->payment_status === 'pagado' ? 'store_order_receipt' : 'store_order_confirmation',
            ['order_id' => $order->id, 'reference' => $order->provider_reference],
        );

        $paid = $order->payment_status === 'pagado';
        $displayAmounts = $order->display_amounts;
        $details = ["Pedido #{$order->id}"];
        if ($displayAmounts['currency'] === 'USD') {
            $details[] = 'Total referencial: $'.number_format((float) $displayAmounts['total'], 2, '.', ',').' USD.';
            $details[] = 'Importe exacto a coordinar y pagar: S/ '.number_format((float) $order->total, 2, '.', ',').' PEN.';
            $details[] = 'Tipo de cambio aplicado: '.$displayAmounts['exchange_rate'].' PEN por USD.';
        } else {
            $details[] = 'Total: S/ '.number_format((float) $order->total, 2, '.', ',').' PEN.';
        }
        if (! $paid) {
            if ($order->payment_method === 'direct_coordination') {
                $details[] = 'No se realizó un cobro automático. DigitalPyme confirmará disponibilidad y coordinará contigo las instrucciones de pago.';
                $whatsapp = SiteSetting::publicSettings()['contact']['whatsapp_phone'];
                if (filled($whatsapp)) {
                    $details[] = 'WhatsApp de coordinación: '.$whatsapp;
                }
                $details[] = 'Puedes responder a este correo o consultar el pedido desde tu cuenta.';
                if ($order->payment_due_at) {
                    $details[] = 'Mantendremos el stock reservado hasta el '.$order->payment_due_at->format('d/m/Y H:i').'.';
                }
            } else {
                $details[] = 'El pago debe completarse antes del '.($order->payment_due_at?->format('d/m/Y H:i') ?? 'plazo indicado en el portal');
                $details[] = match ($order->payment_method) {
                    'bank_transfer' => 'Realiza la transferencia a la cuenta bancaria indicada en tu portal y adjunta el comprobante.',
                    'yape_plin' => 'Realiza el pago por Yape/Plin al número '.(config('services.yape_plin.phone') ?: 'mostrado en tu portal').' y adjunta el comprobante.',
                    default => 'Inicia el pago con tarjeta desde el portal de cliente.',
                };
            }
        } else {
            $details[] = 'Pago confirmado: '.($order->paid_at?->format('d/m/Y H:i') ?? 'sí');
        }
        if ($notifyCustomer) {
            app(CustomerLifecycleNotifier::class)->notify(
                eventType: $paid ? 'store_order_payment_confirmed' : 'store_order_created',
                email: $email,
                subject: $paid
                    ? "Pago confirmado · Pedido #{$order->id}"
                    : ($order->payment_method === 'direct_coordination'
                        ? "Solicitud recibida · Pedido #{$order->id}"
                        : "Pedido recibido · #{$order->id}"),
                intro: $paid
                    ? 'Confirmamos tu pago. Adjuntamos el resumen informativo de tu compra.'
                    : ($order->payment_method === 'direct_coordination'
                        ? 'Recibimos tu pedido. No se realizó un cobro automático; coordinaremos contigo la disponibilidad y las instrucciones de pago.'
                        : 'Recibimos tu pedido y el pago está pendiente.'),
                details: $details,
                userId: $order->client?->user_id,
                actionLabel: 'Ver mi pedido',
                actionUrl: rtrim(config('services.digitalpyme.frontend_url'), '/').'/portal?tab=pedidos',
                resourceType: 'store_order',
                resourceId: $order->id,
                sendEmail: false,
                sendDatabase: true,
            );
        }

        return $emailSent;
    }
}
