<?php

namespace App\Support;

use App\Models\ProjectPayment;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\URL;

class PaymentConfirmationLinks
{
    private const LINK_LIFETIME_DAYS = 30;

    public function forProject(ProjectPayment $payment): array
    {
        $expiresAt = now()->addDays(self::LINK_LIFETIME_DAYS);

        return [
            'show' => URL::temporarySignedRoute(
                'payment-confirmations.project.show',
                $expiresAt,
                ['payment' => $payment->getKey()],
            ),
            'pdf' => URL::temporarySignedRoute(
                'payment-confirmations.project.pdf',
                $expiresAt,
                ['payment' => $payment->getKey()],
            ),
            'email' => URL::temporarySignedRoute(
                'payment-confirmations.project.email',
                $expiresAt,
                ['payment' => $payment->getKey()],
            ),
        ];
    }

    public function forOrder(StoreOrder $order): array
    {
        $expiresAt = now()->addDays(self::LINK_LIFETIME_DAYS);

        return [
            'show' => URL::temporarySignedRoute(
                'payment-confirmations.order.show',
                $expiresAt,
                ['storeOrder' => $order->getKey()],
            ),
            'pdf' => URL::temporarySignedRoute(
                'payment-confirmations.order.pdf',
                $expiresAt,
                ['storeOrder' => $order->getKey()],
            ),
            'email' => URL::temporarySignedRoute(
                'payment-confirmations.order.email',
                $expiresAt,
                ['storeOrder' => $order->getKey()],
            ),
        ];
    }
}
