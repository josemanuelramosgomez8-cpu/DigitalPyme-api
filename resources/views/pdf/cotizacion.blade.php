<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.6; }
        h1 { color: #173c70; font-size: 24px; margin-bottom: 4px; }
        h2 { color: #173c70; font-size: 15px; margin-top: 24px; }
        .muted { color: #64748b; }
        .box { background: #f1f5f9; border-radius: 6px; padding: 14px; margin: 18px 0; }
        .total { color: #173c70; font-size: 20px; font-weight: bold; }
        .scope { white-space: pre-line; }
        footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 10px; margin-top: 30px; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>Cotización DigitalPyme</h1>
    <p class="muted">Cotización #{{ $cotizacion->id }} · {{ $cotizacion->created_at->format('d/m/Y') }}</p>

    <div class="box">
        <strong>Cliente:</strong> {{ $cotizacion->lead->nombre }}<br>
        <strong>Empresa:</strong> {{ $cotizacion->lead->empresa ?: 'No especificada' }}<br>
        <strong>Servicio:</strong> {{ $cotizacion->servicio }}
    </div>

    <h2>Alcance de la propuesta</h2>
    <div class="scope">{{ $cotizacion->descripcion ?: 'El alcance será coordinado con el cliente antes de iniciar el proyecto.' }}</div>

    <div class="box">
        <div class="muted">Monto total cotizado</div>
        <div class="total">S/ {{ number_format((float) $cotizacion->monto, 2, '.', ',') }}</div>
    </div>

    <p>La propuesta y su alcance deben ser confirmados antes de habilitar cualquier pago.</p>
    <footer>DigitalPyme · Cotización informativa. Este documento no es comprobante de pago ni documento fiscal.</footer>
</body>
</html>
