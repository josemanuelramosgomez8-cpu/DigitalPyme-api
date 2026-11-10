<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.6; }
        h1 { color: #157347; font-size: 24px; }
        .muted { color: #64748b; }
        .box { background: #f1f5f9; border-radius: 6px; padding: 14px; margin: 18px 0; }
        .total { color: #173c70; font-size: 22px; font-weight: bold; }
        footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 10px; margin-top: 30px; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>Pago confirmado</h1>
    <p class="muted">Recibo DP-{{ $payment->paid_at->format('Y') }}-{{ str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT) }}</p>

    <div class="box">
        <strong>Cliente:</strong> {{ $payment->project->client->nombre }}<br>
        <strong>Proyecto:</strong> {{ $payment->project->nombre }}<br>
        <strong>Concepto:</strong> {{ $payment->milestone?->titulo ?: 'Pago de proyecto' }}<br>
        <strong>Referencia:</strong> {{ $payment->reference }}<br>
        <strong>Fecha de pago:</strong> {{ $payment->paid_at->format('d/m/Y H:i') }}
    </div>

    <div class="box">
        <div class="muted">Total recibido</div>
        <div class="total">S/ {{ number_format((float) $payment->amount, 2, '.', ',') }}</div>
        <div>Medio: {{ $payment->method === 'card' ? 'Tarjeta · Mercado Pago' : ($payment->method === 'yape_plin' ? 'Yape / Plin' : 'Transferencia bancaria') }}</div>
    </div>

    <footer>Constancia informativa de pago. No reemplaza una boleta o factura electrónica de SUNAT.</footer>
</body>
</html>
