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
        .total { color: #102c2c; font-size: 22px; font-weight: bold; text-align: right; }
        footer { border-top: 1px solid #dfe5dd; color: #52635e; font-size: 10px; margin-top: 30px; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ $order->payment_status === 'pagado' && $order->paid_at ? 'Confirmación de pago' : 'Confirmación de pedido' }}</h1>
    <p class="muted">Comprobante informativo DP-ORD-{{ str_pad((string) $order->id, 8, '0', STR_PAD_LEFT) }} · {{ ($order->paid_at ?? $order->created_at)->format('d/m/Y H:i') }}</p>
    <p><strong>Estado del pago:</strong> {{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}@if ($order->paid_at) · Pagado el {{ $order->paid_at->format('d/m/Y H:i') }}@endif</p>

    <div class="box">
        <strong>Cliente:</strong> {{ $order->nombre_cliente ?: ($order->client?->nombre ?? 'No especificado') }}<br>
        @if ($order->empresa_facturacion)
            <strong>Razón social:</strong> {{ $order->empresa_facturacion }}<br>
        @endif
        <strong>Correo:</strong> {{ $order->email_cliente ?: ($order->client?->user?->email ?? 'No especificado') }}<br>
        <strong>Teléfono:</strong> {{ $order->telefono_cliente ?: 'No especificado' }}<br>
        <strong>{{ strtoupper($order->tipo_documento ?: 'Documento') }}:</strong> {{ $order->numero_documento ?: 'No especificado' }}<br>
        <strong>Comprobante solicitado:</strong> {{ ucfirst($order->tipo_comprobante ?: 'No especificado') }}{{ $order->empresa_facturacion ? ' · '.$order->empresa_facturacion : '' }}<br>
        <strong>Dirección:</strong> {{ $order->ubicacion_entrega }} · {{ $order->direccion_entrega }}<br>
        <strong>Referencia de operación:</strong> {{ $order->provider_payment_id ?: $order->provider_reference ?: 'Pedido #'.$order->id }}<br>
        <strong>Método de pago:</strong> {{ match ($order->payment_method) {
            'bank_transfer' => 'Transferencia bancaria',
            'yape_plin' => 'Yape / Plin',
            'card' => 'Tarjeta · Mercado Pago',
            'direct_coordination' => 'Coordinación directa',
            default => 'No especificado',
        } }}
    </div>

    <table>
        <thead><tr><th>Producto / servicio</th><th>Cantidad</th><th>Precio unitario</th><th>Total</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ $currencySymbol }} {{ number_format((float) $item['unit_price'], 2, '.', ',') }}</td>
                <td>{{ $currencySymbol }} {{ number_format((float) $item['total'], 2, '.', ',') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="box">
        <div>Subtotal: {{ $currencySymbol }} {{ number_format((float) $amounts['subtotal'], 2, '.', ',') }}</div>
        @if ($order->coupon_code)
            <div>Descuento ({{ $order->coupon_code }}): − {{ $currencySymbol }} {{ number_format((float) $amounts['discount'], 2, '.', ',') }}</div>
        @endif
        <div>Envío: {{ $currencySymbol }} {{ number_format((float) $amounts['shipping'], 2, '.', ',') }}</div>
        <div>IGV ({{ number_format((float) $order->igv_rate, 0) }}%): {{ $currencySymbol }} {{ number_format((float) $amounts['igv'], 2, '.', ',') }}</div>
        <div class="total">Total: {{ $currencySymbol }} {{ number_format((float) $amounts['total'], 2, '.', ',') }} {{ $currency }}</div>
    </div>

    @if ($currency === 'USD')
        <p>Importe referencial en USD. El pedido se confirma y paga en PEN: S/ {{ number_format((float) $order->total, 2, '.', ',') }}. Tipo de cambio aplicado: {{ $order->display_exchange_rate }} PEN por USD.</p>
    @endif

    <footer>Comprobante informativo de pago/pedido. No es una boleta o factura electrónica emitida por SUNAT.</footer>
</body>
</html>
