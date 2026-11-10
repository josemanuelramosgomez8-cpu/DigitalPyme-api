<?php

namespace App\Support;

use App\Models\ProjectPayment;
use Barryvdh\DomPDF\Facade\Pdf;

class PaymentReceiptPdf
{
    public static function render(ProjectPayment $payment): string
    {
        return Pdf::loadView('pdf.payment-receipt', [
            'payment' => $payment->loadMissing([
                'project.client.user',
                'project.cotizacion',
                'milestone',
            ]),
        ])->setPaper('a4')->output();
    }
}
