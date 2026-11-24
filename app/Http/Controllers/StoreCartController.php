<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStoreCartRequest;
use App\Models\StoreCart;
use App\Support\StoreCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreCartController extends Controller
{
    public function show(Request $request)
    {
        $cart = StoreCart::query()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'items' => $cart?->items ?? [],
            'currency' => $cart?->currency ?? 'PEN',
            'communications_consent' => $cart?->communications_consented_at !== null
                && $cart?->unsubscribed_at === null,
        ]);
    }

    public function update(UpdateStoreCartRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        $cart = DB::transaction(function () use ($data, $user) {
            $lockedUser = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            $cart = StoreCart::query()->where('user_id', $lockedUser->id)->lockForUpdate()->first()
                ?? new StoreCart(['user_id' => $lockedUser->id]);
            $currency = $data['currency'] ?? $cart->currency ?? 'PEN';
            if ($currency === 'USD' && StoreCurrency::usdExchangeRate() === null) {
                throw ValidationException::withMessages([
                    'currency' => 'La visualización en USD no está disponible hasta configurar el tipo de cambio.',
                ]);
            }
            $consentedAt = $data['communications_consent']
                ? ($cart->communications_consented_at ?? now())
                : null;

            $cart->fill([
                'email' => $lockedUser->email,
                'items' => $data['items'],
                'currency' => $currency,
                'last_activity_at' => now(),
                'recovered_at' => null,
                'communications_consented_at' => $consentedAt,
                'unsubscribed_at' => $data['communications_consent']
                    ? null
                    : ($cart->communications_consented_at ? now() : $cart->unsubscribed_at),
            ]);
            $cart->save();

            return $cart;
        });

        return response()->json([
            'cart_id' => $cart->id,
            'currency' => $cart->currency,
            'communications_consent' => $cart->communications_consented_at !== null,
        ]);
    }

    public function clear(Request $request)
    {
        $cart = StoreCart::query()->where('user_id', $request->user()->id)->first();
        if ($cart) {
            $cart->update([
                'items' => [],
                'last_activity_at' => now(),
            ]);
        }

        return response()->json(['mensaje' => 'Carrito eliminado.']);
    }

    public function restore(Request $request, StoreCart $storeCart)
    {
        abort_if(
            $storeCart->recovered_at
                || $storeCart->communications_consented_at === null
                || $storeCart->unsubscribed_at !== null
                || empty($storeCart->items),
            410,
            'Este carrito ya no está disponible para restaurarse.',
        );

        if ($request->expectsJson()) {
            return response()->json([
                'items' => $storeCart->items,
                'currency' => $storeCart->currency,
                'communications_consent' => true,
            ]);
        }

        $path = $request->getPathInfo().'?'.$request->getQueryString();

        return redirect(
            rtrim(config('services.digitalpyme.frontend_url'), '/')
                .'/carrito?restore='.rawurlencode($path),
        );
    }

    public function unsubscribe(Request $request, StoreCart $storeCart)
    {
        $storeCart->update([
            'communications_consented_at' => null,
            'unsubscribed_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['mensaje' => 'Se registró tu baja de los recordatorios.']);
        }

        return redirect(
            rtrim(config('services.digitalpyme.frontend_url'), '/')
                .'/carrito?reminders=off',
        );
    }
}
