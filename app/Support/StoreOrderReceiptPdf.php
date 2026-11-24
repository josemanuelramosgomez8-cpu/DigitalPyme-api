<?php

namespace App\Support;

use App\Models\StoreOrder;
use App\Support\StoreCurrency;
use Barryvdh\DomPDF\Facade\Pdf;
use LogicException;

class StoreOrderReceiptPdf
{
    public static function render(StoreOrder $order): string
    {
        $order->loadMissing('items', 'client.user');
        $amounts = $order->display_amounts;
        $currency = $amounts['currency'];
        $rate = $amounts['exchange_rate'];
        $convert = static function (string|int|float $amount) use ($currency, $rate): string {
            if ($currency === 'PEN') {
                return (string) $amount;
            }

            return StoreCurrency::toUsd($amount, $rate)
                ?? throw new LogicException('No se pudo convertir el monto con el tipo de cambio del pedido.');
        };
        $items = $order->items->map(fn ($item) => [
            'name' => $item->nombre,
            'quantity' => $item->cantidad,
            'unit_price' => $convert($item->precio_unitario),
            'total' => $convert($item->subtotal),
        ]);

        return Pdf::loadView('pdf.store-order-receipt', [
            'order' => $order,
            'amounts' => $amounts,
            'items' => $items,
            'currency' => $currency,
            'currencySymbol' => $currency === 'USD' ? '$' : 'S/',
        ])->setPaper('a4')->output();
    }
}
