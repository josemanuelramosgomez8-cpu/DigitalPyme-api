<h1>{{ $order->payment_status === 'pagado' ? 'Pago confirmado' : ($order->payment_method === 'direct_coordination' ? 'Solicitud recibida' : '¡Gracias por tu compra!') }}</h1>

<p>Hola {{ $order->nombre_cliente ?: ($order->client?->nombre ?? 'cliente') }},</p>
<p>{{ $order->payment_status === 'pagado' ? 'Confirmamos tu pago del pedido' : ($order->payment_method === 'direct_coordination' ? 'Recibimos tu solicitud de pedido' : 'Recibimos tu pedido') }} <strong>#{{ $order->id }}</strong>. Adjuntamos el resumen informativo de la compra.</p>

@if ($order->display_amounts['currency'] === 'USD')
    <p><strong>Total referencial:</strong> ${{ number_format((float) $order->display_amounts['total'], 2, '.', ',') }} USD</p>
    <p><strong>Importe exacto a coordinar y pagar:</strong> S/ {{ number_format((float) $order->total, 2, '.', ',') }} PEN</p>
    <p><small>Tipo de cambio aplicado: {{ $order->display_amounts['exchange_rate'] }} PEN por USD.</small></p>
@else
    <p><strong>Total:</strong> S/ {{ number_format((float) $order->total, 2, '.', ',') }} PEN</p>
@endif
<p><strong>Estado del pago:</strong> {{ str_replace('_', ' ', $order->payment_status) }}</p>
@if ($order->paid_at)
    <p><strong>Fecha de pago:</strong> {{ $order->paid_at->format('d/m/Y H:i') }}</p>
@endif
<p><strong>Entrega:</strong> {{ $order->ubicacion_entrega }} · {{ $order->direccion_entrega }}</p>
<p><a href="{{ $confirmationUrl }}">Consultar el estado y comprobante informativo del pedido</a></p>
@if ($order->payment_method === 'direct_coordination' && $order->payment_status !== 'pagado')
    <p>No se realizó un cobro automático. Confirmaremos la disponibilidad y coordinaremos contigo las instrucciones de pago.</p>
    @if ($coordinationWhatsapp)
        <p>WhatsApp para coordinar: <a href="https://wa.me/{{ preg_replace('/\D+/', '', $coordinationWhatsapp) }}">{{ $coordinationWhatsapp }}</a></p>
    @endif
    @if ($coordinationEmail)
        <p>También puedes responder a este correo o escribir a <a href="mailto:{{ $coordinationEmail }}">{{ $coordinationEmail }}</a>.</p>
    @endif
    @if ($order->payment_due_at)
        <p>El stock queda reservado hasta {{ $order->payment_due_at->format('d/m/Y H:i') }}; después, el pedido puede vencer.</p>
    @endif
@endif
<p>Puedes consultar el estado desde tu cuenta de cliente.</p>

<p>El PDF adjunto es una constancia informativa del pedido; no reemplaza una boleta o factura electrónica emitida por SUNAT.</p>
