<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #102c2c; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.6; }
        h1 { color: #102c2c; font-size: 24px; }
        .muted { color: #52635e; }
        .box { background: #f6f4ed; border-radius: 6px; padding: 14px; margin: 18px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 7px; border-bottom: 1px solid #dfe5dd; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
        .total { color: #102c2c; font-size: 22px; font-weight: bold; }
        footer { border-top: 1px solid #dfe5dd; color: #52635e; font-size: 10px; margin-top: 30px; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>Confirmación de pago</h1>
    <p class="muted">Comprobante informativo DP-PAY-{{ str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT) }} · {{ $payment->paid_at->format('d/m/Y H:i') }}</p>

    <div class="box">
        <strong>Cliente:</strong> {{ $payment->project->client->nombre }}<br>
        @if ($payment->project->client->empresa)
            <strong>Razón social:</strong> {{ $payment->project->client->empresa }}<br>
        @endif
        <strong>{{ strtoupper($payment->project->client->tipo_documento ?: 'Documento') }}:</strong> {{ $payment->project->client->numero_documento ?: 'No especificado' }}<br>
        <strong>Correo:</strong> {{ $payment->project->client->user?->email ?: 'No especificado' }}<br>
        <strong>Teléfono:</strong> {{ $payment->project->client->telefono ?: 'No especificado' }}<br>
        <strong>Dirección:</strong> {{ $payment->project->client->direccion_entrega ?: 'No especificada' }}<br>
        <strong>Proyecto:</strong> {{ $payment->project->nombre }}<br>
        <strong>Concepto:</strong> {{ $payment->milestone?->titulo ?: 'Pago de proyecto' }}<br>
        <strong>Referencia de operación:</strong> {{ $payment->provider_reference ?: $payment->reference }}<br>
        <strong>Condición:</strong> Pago confirmado<br>
        <strong>Método de pago:</strong> {{ $payment->method === 'card' ? 'Tarjeta · Mercado Pago' : ($payment->method === 'yape_plin' ? 'Yape / Plin' : 'Transferencia bancaria') }}
    </div>

    <table>
        <thead><tr><th>Servicio / concepto</th><th>Cantidad</th><th>Precio unitario</th><th>Total</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $payment->milestone?->titulo ?: ($payment->project->cotizacion?->servicio ?: $payment->project->nombre) }}</td>
                <td>1</td>
                <td>{{ $payment->currency === 'USD' ? '$' : 'S/' }} {{ number_format((float) $payment->amount, 2, '.', ',') }}</td>
                <td>{{ $payment->currency === 'USD' ? '$' : 'S/' }} {{ number_format((float) $payment->amount, 2, '.', ',') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="box">
        <div>Importe del pago registrado: {{ $payment->currency }}</div>
        <div class="total">{{ $payment->currency === 'USD' ? '$' : 'S/' }} {{ number_format((float) $payment->amount, 2, '.', ',') }} {{ $payment->currency }}</div>
    </div>

    <footer>Comprobante informativo de pago. No es una boleta o factura electrónica emitida por SUNAT. No se registra un desglose de impuestos en este pago.</footer>
</body>
</html>
