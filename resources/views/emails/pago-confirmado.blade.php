<h1>Pago confirmado</h1>

<p>Hola {{ $payment->project->client->nombre }},</p>
<p>Confirmamos la recepción de tu pago. Adjuntamos el recibo informativo en PDF.</p>

<p><strong>Recibo:</strong> DP-{{ $payment->paid_at->format('Y') }}-{{ str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT) }}</p>
<p><strong>Proyecto:</strong> {{ $payment->project->nombre }}</p>
<p><strong>Monto:</strong> S/ {{ number_format((float) $payment->amount, 2, '.', ',') }}</p>
<p><strong>Fecha de pago:</strong> {{ $payment->paid_at->format('d/m/Y H:i') }}</p>

<p>Este recibo es informativo y no reemplaza una boleta o factura electrónica de SUNAT.</p>
