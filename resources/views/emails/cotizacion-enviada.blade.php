<h1>Tu cotización de DigitalPyme</h1>

<p>Hola {{ $cotizacion->lead->nombre }},</p>
<p>Adjuntamos el resumen formal de tu cotización en PDF. Revisa el alcance y el monto antes de confirmar el proyecto.</p>

<p><strong>Cotización:</strong> #{{ $cotizacion->id }}</p>
<p><strong>Servicio:</strong> {{ $cotizacion->servicio }}</p>
<p><strong>Total:</strong> S/ {{ number_format((float) $cotizacion->monto, 2, '.', ',') }}</p>

<p>El pago se habilitará únicamente después de confirmar el alcance y aceptar esta propuesta con DigitalPyme.</p>
<p>Si necesitas algún ajuste, responde a este correo.</p>
