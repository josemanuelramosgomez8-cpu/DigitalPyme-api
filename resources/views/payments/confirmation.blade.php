<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Comprobante informativo | DigitalPyme</title>
    <link rel="stylesheet" href="{{ asset('css/payment-confirmation.css') }}">
    <script src="{{ asset('js/payment-confirmation.js') }}" defer></script>
</head>
<body>
    <div class="dp-payment-page">
        <header class="dp-payment-header">
            <a class="dp-payment-brand" href="{{ config('services.digitalpyme.frontend_url') }}">
                DIGITALPYME
            </a>
            <span class="dp-payment-secure">
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none">
                    <rect x="4" y="8" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M7 8V5.8a3 3 0 0 1 6 0V8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    <circle cx="10" cy="12.5" r="1" fill="currentColor"/>
                </svg>
                Enlace de consulta seguro
            </span>
        </header>

        <main class="dp-payment-card">
            <section class="dp-payment-status dp-payment-status--{{ $confirmation['status_style'] }}" aria-labelledby="confirmation-title">
                <span class="dp-payment-status__icon" aria-hidden="true">
                    @if ($confirmation['is_confirmed'])
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="m6 12.5 4 4L18.5 8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M12 7.5v5M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    @endif
                </span>
                <p class="dp-payment-status__eyebrow">Confirmación de pago · Comprobante informativo</p>
                <h1 id="confirmation-title">{{ $confirmation['status_title'] }}</h1>
                <p class="dp-payment-status__label">{{ $confirmation['status_label'] }}</p>
                @if ($confirmation['is_confirmed'])
                    <p class="dp-payment-status__description">
                        El pago figura como confirmado en el registro de DigitalPyme.
                    </p>
                @else
                    <p class="dp-payment-status__description">
                        Esta consulta refleja el estado registrado. No se considera pago confirmado
                        hasta que el sistema o el equipo valide la operación.
                    </p>
                @endif
                <div class="dp-payment-amount">
                    <span>{{ $confirmation['currency_symbol'] }} {{ number_format((float) $confirmation['amount'], 2, '.', ',') }}</span>
                    <small>{{ $confirmation['currency'] }}</small>
                </div>
                @if ($confirmation['currency_note'])
                    <p class="dp-payment-status__note">{{ $confirmation['currency_note'] }}</p>
                @endif
                @if ($confirmation['payment_note'])
                    <p class="dp-payment-status__note">{{ $confirmation['payment_note'] }}</p>
                @endif
                <p class="dp-payment-status__reference">
                    Referencia de operación:
                    <strong>{{ $confirmation['transaction_reference'] }}</strong>
                </p>
            </section>

            @if (session('receipt_email_status') === 'sent')
                <div class="dp-payment-alert dp-payment-alert--success" role="status">
                    El comprobante informativo fue enviado al correo registrado.
                </div>
            @elseif (session('receipt_email_status') === 'failed')
                <div class="dp-payment-alert dp-payment-alert--error" role="alert">
                    No se pudo enviar el correo. Revisa la configuración del servicio de correo e inténtalo nuevamente.
                </div>
            @elseif (session('receipt_email_status') === 'unavailable')
                <div class="dp-payment-alert dp-payment-alert--error" role="alert">
                    No hay un correo válido registrado para este comprobante.
                </div>
            @endif

            <div class="dp-payment-grid">
                <section class="dp-payment-panel" aria-labelledby="customer-title">
                    <h2 id="customer-title">Datos de facturación solicitados</h2>
                    <dl class="dp-payment-details">
                        <div>
                            <dt>Nombre / razón social</dt>
                            <dd>{{ $confirmation['customer']['company'] ?: $confirmation['customer']['name'] }}</dd>
                        </div>
                        <div>
                            <dt>{{ $confirmation['customer']['document_type'] }}</dt>
                            <dd>{{ $confirmation['customer']['document_number'] }}</dd>
                        </div>
                        @if ($confirmation['customer']['requested_document'] ?? null)
                            <div>
                                <dt>Comprobante solicitado</dt>
                                <dd>{{ $confirmation['customer']['requested_document'] }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Dirección</dt>
                            <dd>{{ $confirmation['customer']['address'] }}</dd>
                        </div>
                        <div>
                            <dt>Correo</dt>
                            <dd>{{ $confirmation['customer']['email'] }}</dd>
                        </div>
                        <div>
                            <dt>Teléfono</dt>
                            <dd>{{ $confirmation['customer']['phone'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="dp-payment-panel" aria-labelledby="invoice-details-title">
                    <h2 id="invoice-details-title">Detalles del comprobante</h2>
                    <dl class="dp-payment-details">
                        <div>
                            <dt>Referencia interna</dt>
                            <dd>{{ $confirmation['receipt_reference'] }}</dd>
                        </div>
                        <div>
                            <dt>Fecha de confirmación / registro</dt>
                            <dd>{{ $confirmation['issued_at']?->format('d/m/Y H:i') ?: 'No disponible' }}</dd>
                        </div>
                        <div>
                            <dt>Condición</dt>
                            <dd>{{ $confirmation['payment_condition'] }}</dd>
                        </div>
                        @if ($confirmation['due_at'])
                            <div>
                                <dt>Vencimiento de pago</dt>
                                <dd>{{ $confirmation['due_at']->format('d/m/Y H:i') }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Método de pago</dt>
                            <dd>{{ $confirmation['payment_method'] }}</dd>
                        </div>
                        <div>
                            <dt>Estado</dt>
                            <dd>{{ $confirmation['status_label'] }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="dp-payment-panel dp-payment-items" aria-labelledby="items-title">
                <h2 id="items-title">Detalle de servicios / productos</h2>
                <div class="dp-payment-table-wrap">
                    <table class="dp-payment-table">
                        <thead>
                            <tr>
                                <th scope="col">Concepto</th>
                                <th scope="col">Cantidad</th>
                                <th scope="col">Precio unitario</th>
                                <th scope="col">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($confirmation['items'] as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item['name'] }}</strong>
                                        @if ($item['description'])
                                            <span>{{ $item['description'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $item['quantity'] }}</td>
                                    <td>{{ $confirmation['currency_symbol'] }} {{ number_format((float) $item['unit_price'], 2, '.', ',') }}</td>
                                    <td>{{ $confirmation['currency_symbol'] }} {{ number_format((float) $item['total'], 2, '.', ',') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="dp-payment-totals">
                    @foreach ($confirmation['totals'] as $total)
                        <div class="{{ $total['emphasis'] ? 'dp-payment-total--emphasis' : '' }}">
                            <span>{{ $total['label'] }}</span>
                            <strong>{{ $confirmation['currency_symbol'] }} {{ number_format((float) $total['amount'], 2, '.', ',') }}</strong>
                        </div>
                    @endforeach
                </div>
                <p class="dp-payment-tax-note">{{ $confirmation['tax_note'] }}</p>
            </section>

            <section class="dp-payment-validation" aria-labelledby="validation-title">
                <div>
                    <p class="dp-payment-kicker">Validación de esta consulta</p>
                    <h2 id="validation-title">Referencia del comprobante</h2>
                    <code>{{ $confirmation['receipt_reference'] }}</code>
                    <p>Este enlace permite consultar el estado registrado durante los próximos 30 días.</p>
                </div>
                <a href="{{ $confirmation['validation_url'] }}">Abrir consulta segura</a>
            </section>

            <section class="dp-payment-actions" aria-label="Acciones del comprobante">
                @if ($confirmation['is_confirmed'])
                    <a class="dp-payment-button dp-payment-button--primary" href="{{ $confirmation['pdf_url'] }}">
                        Descargar PDF
                    </a>
                    <form method="POST" action="{{ $confirmation['email_url'] }}">
                        @csrf
                        <button class="dp-payment-button dp-payment-button--secondary" type="submit">
                            Enviar por correo
                        </button>
                    </form>
                @endif
                <button class="dp-payment-button dp-payment-button--quiet" type="button" data-print-trigger>
                    Imprimir
                </button>
                @if ($confirmation['whatsapp_url'])
                    <a class="dp-payment-button dp-payment-button--whatsapp"
                       href="{{ $confirmation['whatsapp_url'] }}"
                       target="_blank"
                       rel="noopener noreferrer">
                        Enviar resumen a DigitalPyme por WhatsApp
                    </a>
                @else
                    <p class="dp-payment-actions__note">
                        El canal de WhatsApp no está configurado; no se generó un enlace de mensaje.
                    </p>
                @endif
            </section>

            <footer class="dp-payment-footer">
                <p>
                    Este es un comprobante informativo de pago y no una factura o boleta electrónica
                    emitida por SUNAT. La emisión tributaria oficial no está integrada en este sistema.
                </p>
                <span>DigitalPyme · {{ now()->format('Y') }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
