<h1>{{ $order->payment_status === 'pagado' ? 'Pago confirmado' : '¡Gracias por tu compra!' }}</h1>

<p>Hola {{ $order->nombre_cliente ?: ($order->client?->nombre ?? 'cliente') }},</p>
<p>{{ $order->payment_status === 'pagado' ? 'Confirmamos tu pago del pedido' : 'Recibimos tu pedido' }} <strong>#{{ $order->id }}</strong>. Adjuntamos el resumen informativo de la compra.</p>

<p><strong>Total:</strong> S/ {{ number_format((float) $order->total, 2, '.', ',') }}</p>
<p><strong>Estado del pago:</strong> {{ str_replace('_', ' ', $order->payment_status) }}</p>
@if ($order->paid_at)
    <p><strong>Fecha de pago:</strong> {{ $order->paid_at->format('d/m/Y H:i') }}</p>
@endif
<p><strong>Entrega:</strong> {{ $order->ubicacion_entrega }} · {{ $order->direccion_entrega }}</p>
<p>Puedes consultar el estado desde tu cuenta de cliente.</p>

<p>El PDF adjunto es una constancia informativa del pedido; no reemplaza una boleta o factura electrónica emitida por SUNAT.</p>
