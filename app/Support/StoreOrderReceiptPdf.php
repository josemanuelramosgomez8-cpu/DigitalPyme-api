<?php

namespace App\Support;

use App\Models\StoreOrder;
use Barryvdh\DomPDF\Facade\Pdf;

class StoreOrderReceiptPdf
{
    public static function render(StoreOrder $order): string
    {
        return Pdf::loadView('pdf.store-order-receipt', [
            'order' => $order->loadMissing('items', 'client.user'),
        ])->setPaper('a4')->output();
    }
}
