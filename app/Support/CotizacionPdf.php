<?php

namespace App\Support;

use App\Models\Cotizacion;
use Barryvdh\DomPDF\Facade\Pdf;

class CotizacionPdf
{
    public static function render(Cotizacion $cotizacion): string
    {
        return Pdf::loadView('pdf.cotizacion', [
            'cotizacion' => $cotizacion->loadMissing('lead'),
        ])->setPaper('a4')->output();
    }
}
