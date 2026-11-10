<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.6; }
        h1 { color: #157347; font-size: 24px; }
        .muted { color: #64748b; }
        .box { background: #f1f5f9; border-radius: 6px; padding: 14px; margin: 18px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 7px; border-bottom: 1px solid #cbd5e1; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
        .total { color: #173c70; font-size: 22px; font-weight: bold; text-align: right; }
        footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 10px; margin-top: 30px; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>Confirmación de pedido</h1>
    <p class="muted">Pedido #{{ str_pad((string) $order->id, 8, '0', STR_PAD_LEFT) }} · {{ $order->created_at->format('d/m/Y H:i') }}</p>
    <p><strong>Estado del pago:</strong> {{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}@if ($order->paid_at) · Pagado el {{ $order->paid_at->format('d/m/Y H:i') }}@endif</p>

    <div class="box">
        <strong>Cliente:</strong> {{ $order->nombre_cliente ?: ($order->client?->nombre ?? 'No especificado') }}<br>
        <strong>Correo:</strong> {{ $order->email_cliente ?: ($order->client?->user?->email ?? 'No especificado') }}<br>
        <strong>Teléfono:</strong> {{ $order->telefono_cliente ?: 'No especificado' }}<br>
        <strong>{{ strtoupper($order->tipo_documento ?: 'Documento') }}:</strong> {{ $order->numero_documento ?: 'No especificado' }}<br>
        <strong>Comprobante solicitado:</strong> {{ ucfirst($order->tipo_comprobante ?: 'No especificado') }}{{ $order->empresa_facturacion ? ' · '.$order->empresa_facturacion : '' }}<br>
        <strong>Entrega:</strong> {{ $order->ubicacion_entrega }} · {{ $order->direccion_entrega }}
    </div>

    <table>
        <thead><tr><th>Producto</th><th>Cantidad</th><th>Subtotal</th></tr></thead>
        <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->nombre }}</td>
                <td>{{ $item->cantidad }}</td>
                <td>S/ {{ number_format((float) $item->subtotal, 2, '.', ',') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="box">
        <div>Subtotal: S/ {{ number_format((float) $order->subtotal, 2, '.', ',') }}</div>
        @if ($order->coupon_code)
            <div>Descuento ({{ $order->coupon_code }}): − S/ {{ number_format((float) $order->discount_amount, 2, '.', ',') }}</div>
        @endif
        <div>Envío: S/ {{ number_format((float) $order->costo_envio, 2, '.', ',') }}</div>
        <div>IGV ({{ number_format((float) $order->igv_rate, 0) }}%): S/ {{ number_format((float) $order->igv_amount, 2, '.', ',') }}</div>
        <div class="total">Total: S/ {{ number_format((float) $order->total, 2, '.', ',') }}</div>
    </div>

    <footer>Constancia informativa de pedido. No reemplaza una boleta o factura electrónica emitida por SUNAT.</footer>
</body>
</html>
