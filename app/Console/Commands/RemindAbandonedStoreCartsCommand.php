<?php

namespace App\Console\Commands;

use App\Models\StoreCart;
use App\Services\CustomerLifecycleNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class RemindAbandonedStoreCartsCommand extends Command
{
    protected $signature = 'carts:remind';

    protected $description = 'Envía recordatorios consentidos de carritos físicos abandonados';

    public function handle(CustomerLifecycleNotifier $notifier): int
    {
        $cartIds = StoreCart::query()
            ->where('reminders_sent', '<', 3)
            ->whereNotNull('communications_consented_at')
            ->whereNull('unsubscribed_at')
            ->whereNull('recovered_at')
            ->where('last_activity_at', '<=', now()->subHour())
            ->orderBy('id')
            ->pluck('id');

        foreach ($cartIds as $cartId) {
            $reminder = DB::transaction(function () use ($cartId) {
                $cart = StoreCart::query()->lockForUpdate()->find($cartId);

                if (
                    ! $cart
                    || $cart->reminders_sent >= 3
                    || ! $cart->communications_consented_at
                    || $cart->unsubscribed_at
                    || $cart->recovered_at
                    || empty($cart->items)
                ) {
                    return null;
                }

                $thresholdHours = [1, 24, 72][$cart->reminders_sent];
                if ($cart->last_activity_at->gt(now()->subHours($thresholdHours))) {
                    return null;
                }

                $cart->increment('reminders_sent');
                $cart->update(['last_reminder_at' => now()]);

                return [
                    'id' => $cart->id,
                    'email' => $cart->email,
                    'user_id' => $cart->user_id,
                    'reminder_number' => $cart->reminders_sent,
                    'items_count' => count($cart->items),
                ];
            });

            if (! $reminder) {
                continue;
            }

            $restorePath = URL::signedRoute(
                'store.carts.restore',
                ['storeCart' => $reminder['id']],
                null,
                false,
            );
            $unsubscribePath = URL::signedRoute(
                'store.carts.unsubscribe',
                ['storeCart' => $reminder['id']],
                null,
                false,
            );
            $frontendUrl = rtrim(config('services.digitalpyme.frontend_url'), '/').'/carrito';
            $coupon = $reminder['reminder_number'] === 3
                ? config('services.digitalpyme.cart_abandoned_coupon')
                : null;

            $notifier->notify(
                eventType: 'store_cart_abandoned_reminder',
                email: $reminder['email'],
                subject: 'Tu carrito te está esperando',
                intro: 'Guardamos los productos de tu carrito físico para que puedas continuar cuando quieras.',
                details: array_filter([
                    'Recordatorio' => $reminder['reminder_number'].' de 3',
                    'Productos guardados' => (string) $reminder['items_count'],
                    'Cupón opcional' => $coupon ?: null,
                ]),
                userId: $reminder['user_id'],
                actionLabel: 'Restaurar mi carrito',
                actionUrl: $frontendUrl.'?restore='.rawurlencode($restorePath),
                unsubscribeUrl: $frontendUrl.'?unsubscribe='.rawurlencode($unsubscribePath),
                resourceType: 'store_cart',
                resourceId: $reminder['id'],
                sendDatabase: false,
            );
        }

        $this->info('Recordatorios de carritos procesados.');

        return self::SUCCESS;
    }
}
