<?php

namespace App\Http\Controllers;

use App\Mail\PagoConfirmado;
use App\Mail\StoreOrderConfirmation;
use App\Models\ProjectPayment;
use App\Models\SiteSetting;
use App\Models\StoreOrder;
use App\Support\PaymentConfirmationLinks;
use App\Support\PaymentReceiptPdf;
use App\Support\ReceiptEmailDelivery;
use App\Support\StoreCurrency;
use App\Support\StoreOrderReceiptPdf;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentConfirmationController extends Controller
{
    public function showProject(ProjectPayment $payment, PaymentConfirmationLinks $links)
    {
        $payment->loadMissing(['project.client.user', 'project.cotizacion', 'milestone']);
        $project = $payment->project;
        $client = $project->client;
        $confirmed = $payment->status === 'pagado' && $payment->paid_at !== null;
        $amount = (string) $payment->amount;
        $concept = $payment->milestone?->titulo
            ?: $project->cotizacion?->servicio
            ?: $project->nombre;
        $description = $payment->milestone?->titulo
            ? 'Pago correspondiente al hito aprobado.'
            : ($project->cotizacion?->descripcion ?: $project->descripcion);
        $reference = $payment->reference;
        $urls = $links->forProject($payment);

        return $this->confirmationView([
            'confirmation' => [
                'kind' => 'project',
                'status_title' => $this->statusTitle($payment->status, $confirmed),
                'status_label' => $this->statusLabel($payment->status, $confirmed),
                'status_style' => $this->statusStyle($payment->status, $confirmed),
                'status' => $payment->status,
                'is_confirmed' => $confirmed,
                'payment_note' => null,
                'amount' => $amount,
                'currency' => $payment->currency,
                'currency_symbol' => $this->currencySymbol($payment->currency),
                'reference' => $reference,
                'transaction_reference' => $payment->provider_reference ?: $reference,
                'receipt_reference' => 'DP-PAY-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT),
                'issued_at' => $payment->paid_at ?? $payment->created_at,
                'due_at' => null,
                'payment_condition' => $confirmed ? 'Pago confirmado' : $this->statusLabel($payment->status, false),
                'payment_method' => $this->paymentMethodLabel($payment->method),
                'customer' => $this->customerDetails(
                    $client?->nombre,
                    $client?->empresa,
                    $client?->tipo_documento,
                    $client?->numero_documento,
                    $client?->direccion_entrega,
                    $client?->telefono,
                    $client?->user?->email,
                ),
                'items' => [[
                    'name' => $concept,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'total' => $amount,
                ]],
                'totals' => [
                    ['label' => 'Importe del pago registrado', 'amount' => $amount, 'emphasis' => true],
                ],
                'tax_note' => 'No hay un desglose de impuestos asociado a este pago en el sistema.',
                'currency_note' => null,
                'pdf_url' => $confirmed ? $urls['pdf'] : null,
                'email_url' => $confirmed ? $urls['email'] : null,
                'whatsapp_url' => $this->whatsappUrl(
                    $this->formatWhatsAppMessage(
                        $reference,
                        $amount,
                        $payment->currency,
                        $payment->method,
                        $payment->status,
                        $confirmed,
                        ($client?->tipo_documento ? strtoupper($client->tipo_documento) : 'Documento')
                            .': '.($client?->numero_documento ?: 'No especificado'),
                        $urls['show'],
                    ),
                ),
                'validation_url' => $urls['show'],
            ],
        ]);
    }

    public function showOrder(StoreOrder $storeOrder, PaymentConfirmationLinks $links)
    {
        $storeOrder->loadMissing(['items', 'client.user']);
        $customerEmail = $storeOrder->email_cliente ?: $storeOrder->client?->user?->email;
        $amounts = $storeOrder->display_amounts;
        $currency = $amounts['currency'];
        $rate = $amounts['exchange_rate'];
        $confirmed = $storeOrder->payment_status === 'pagado' && $storeOrder->paid_at !== null;
        $urls = $links->forOrder($storeOrder);
        $items = $storeOrder->items->map(function ($item) use ($currency, $rate) {
            $unitPrice = $this->displayAmount((string) $item->precio_unitario, $currency, $rate);
            $total = $this->displayAmount((string) $item->subtotal, $currency, $rate);

            return [
                'name' => $item->nombre,
                'description' => null,
                'quantity' => $item->cantidad,
                'unit_price' => $unitPrice,
                'total' => $total,
            ];
        })->all();

        $reference = $storeOrder->provider_payment_id
            ?: $storeOrder->provider_reference
            ?: 'PEDIDO-'.str_pad((string) $storeOrder->id, 8, '0', STR_PAD_LEFT);
        $displayTotal = $amounts['total'];
        $actualPen = (string) $storeOrder->total;
        $currencyNote = $currency === 'USD'
            ? 'Importe referencial en USD. El pedido se confirma y paga en PEN; importe registrado: S/ '.number_format((float) $actualPen, 2, '.', ',').'. Tipo de cambio aplicado: '.$rate.' PEN por USD.'
            : null;

        return $this->confirmationView([
            'confirmation' => [
                'kind' => 'order',
                'status_title' => $this->statusTitle($storeOrder->payment_status, $confirmed),
                'status_label' => $this->statusLabel($storeOrder->payment_status, $confirmed),
                'status_style' => $this->statusStyle($storeOrder->payment_status, $confirmed),
                'status' => $storeOrder->payment_status,
                'is_confirmed' => $confirmed,
                'payment_note' => $storeOrder->payment_method === 'direct_coordination' && ! $confirmed
                    ? 'No se realizó un cobro automático. DigitalPyme confirmará disponibilidad y coordinará contigo las instrucciones de pago.'
                    : null,
                'amount' => $displayTotal,
                'currency' => $currency,
                'currency_symbol' => $this->currencySymbol($currency),
                'reference' => 'PEDIDO-'.str_pad((string) $storeOrder->id, 8, '0', STR_PAD_LEFT),
                'transaction_reference' => $reference,
                'receipt_reference' => 'DP-ORD-'.str_pad((string) $storeOrder->id, 8, '0', STR_PAD_LEFT),
                'issued_at' => $storeOrder->paid_at ?? $storeOrder->created_at,
                'due_at' => $storeOrder->payment_due_at,
                'payment_condition' => $confirmed ? 'Pago confirmado' : $this->statusLabel($storeOrder->payment_status, false),
                'payment_method' => $this->paymentMethodLabel($storeOrder->payment_method),
                'customer' => $this->customerDetails(
                    $storeOrder->nombre_cliente ?: $storeOrder->client?->nombre,
                    $storeOrder->empresa_facturacion ?: $storeOrder->client?->empresa,
                    $storeOrder->tipo_documento,
                    $storeOrder->numero_documento,
                    $storeOrder->direccion_entrega,
                    $storeOrder->telefono_cliente,
                    $customerEmail,
                ) + [
                    'requested_document' => $storeOrder->tipo_comprobante
                        ? ucfirst($storeOrder->tipo_comprobante)
                        : 'No especificado',
                ],
                'items' => $items,
                'totals' => $this->orderTotals($storeOrder, $amounts),
                'tax_note' => 'El IGV mostrado corresponde al cálculo guardado en el pedido; esta constancia no es un comprobante tributario emitido por SUNAT.',
                'currency_note' => $currencyNote,
                'pdf_url' => $confirmed ? $urls['pdf'] : null,
                'email_url' => $confirmed ? $urls['email'] : null,
                'whatsapp_url' => $this->whatsappUrl(
                    $this->formatWhatsAppMessage(
                        $reference,
                        (string) $displayTotal,
                        $currency,
                        $storeOrder->payment_method,
                        $storeOrder->payment_status,
                        $confirmed,
                        ($storeOrder->tipo_documento ? strtoupper($storeOrder->tipo_documento) : 'Documento')
                            .': '.($storeOrder->numero_documento ?: 'No especificado'),
                        $urls['show'],
                    ),
                ),
                'validation_url' => $urls['show'],
            ],
        ]);
    }

    public function projectPdf(ProjectPayment $payment): Response
    {
        abort_unless($payment->status === 'pagado' && $payment->paid_at, 404);

        return $this->pdfResponse(
            PaymentReceiptPdf::render($payment),
            'recibo-digitalpyme-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT).'.pdf',
        );
    }

    public function orderPdf(StoreOrder $storeOrder): Response
    {
        abort_unless($storeOrder->payment_status === 'pagado' && $storeOrder->paid_at, 404);

        return $this->pdfResponse(
            StoreOrderReceiptPdf::render($storeOrder),
            'pedido-digitalpyme-'.str_pad((string) $storeOrder->id, 8, '0', STR_PAD_LEFT).'.pdf',
        );
    }

    public function resendProjectEmail(
        ProjectPayment $payment,
        PaymentConfirmationLinks $links,
        ReceiptEmailDelivery $emailDelivery,
    ): RedirectResponse {
        abort_unless($payment->status === 'pagado' && $payment->paid_at, 404);
        $payment->loadMissing('project.client.user');
        $email = $payment->project?->client?->user?->email;

        if (blank($email)) {
            return redirect()->to($links->forProject($payment)['show'])
                ->with('receipt_email_status', 'unavailable');
        }

        $sent = $emailDelivery->send(
            new PagoConfirmado($payment),
            $email,
            'project_payment_receipt',
            ['payment_id' => $payment->id, 'reference' => $payment->reference],
        );

        return redirect()->to($links->forProject($payment)['show'])
            ->with('receipt_email_status', $sent ? 'sent' : 'failed');
    }

    public function resendOrderEmail(
        StoreOrder $storeOrder,
        PaymentConfirmationLinks $links,
        ReceiptEmailDelivery $emailDelivery,
    ): RedirectResponse {
        abort_unless($storeOrder->payment_status === 'pagado' && $storeOrder->paid_at, 404);
        $storeOrder->loadMissing('client.user');
        $email = $storeOrder->email_cliente ?: $storeOrder->client?->user?->email;

        if (blank($email)) {
            return redirect()->to($links->forOrder($storeOrder)['show'])
                ->with('receipt_email_status', 'unavailable');
        }

        $sent = $emailDelivery->send(
            new StoreOrderConfirmation($storeOrder),
            $email,
            'store_order_receipt',
            ['order_id' => $storeOrder->id, 'reference' => $storeOrder->provider_reference],
        );

        return redirect()->to($links->forOrder($storeOrder)['show'])
            ->with('receipt_email_status', $sent ? 'sent' : 'failed');
    }

    private function customerDetails(
        ?string $name,
        ?string $company,
        ?string $documentType,
        ?string $documentNumber,
        ?string $address,
        ?string $phone,
        ?string $email,
    ): array {
        return [
            'name' => $name ?: 'No especificado',
            'company' => $company,
            'document_type' => $documentType ? strtoupper($documentType) : 'Documento',
            'document_number' => $documentNumber ?: 'No especificado',
            'address' => $address ?: 'No especificada',
            'phone' => $phone ?: 'No especificado',
            'email' => $email ?: 'No especificado',
        ];
    }

    private function orderTotals(StoreOrder $order, array $amounts): array
    {
        $rate = $amounts['exchange_rate'];
        $currency = $amounts['currency'];
        $totals = [
            ['label' => 'Subtotal', 'amount' => $amounts['subtotal'], 'emphasis' => false],
        ];

        if ((float) $order->discount_amount > 0) {
            $totals[] = [
                'label' => 'Descuento'.($order->coupon_code ? ' ('.$order->coupon_code.')' : ''),
                'amount' => '-'.$this->displayAmount((string) $order->discount_amount, $currency, $rate),
                'emphasis' => false,
            ];
        }

        $totals[] = ['label' => 'Envío', 'amount' => $amounts['shipping'], 'emphasis' => false];
        $totals[] = [
            'label' => 'IGV ('.number_format((float) $order->igv_rate, 0). '%)',
            'amount' => $amounts['igv'],
            'emphasis' => false,
        ];
        $totals[] = ['label' => 'Total', 'amount' => $amounts['total'], 'emphasis' => true];

        return $totals;
    }

    private function displayAmount(string $penAmount, string $currency, ?string $rate): string
    {
        if ($currency === 'PEN') {
            return $penAmount;
        }

        $converted = StoreCurrency::toUsd($penAmount, $rate);
        abort_if($converted === null, 500, 'No se pudo convertir el monto con el tipo de cambio registrado.');

        return $converted;
    }

    private function statusTitle(string $status, bool $confirmed): string
    {
        if ($confirmed) {
            return 'Pago recibido exitosamente';
        }

        return match ($status) {
            'comprobante_enviado' => 'Comprobante enviado para revisión',
            'rechazado', 'fallido' => 'Pago no confirmado',
            'expirado' => 'Pedido vencido',
            'pagado' => 'Pago en validación',
            default => 'Pago pendiente',
        };
    }

    private function statusLabel(string $status, bool $confirmed): string
    {
        if ($confirmed) {
            return 'Pagado y confirmado';
        }

        return match ($status) {
            'comprobante_enviado' => 'Pendiente de revisión',
            'rechazado' => 'Rechazado',
            'fallido' => 'Fallido',
            'expirado' => 'Vencido',
            'pagado' => 'Pendiente de verificación',
            default => 'Pendiente',
        };
    }

    private function statusStyle(string $status, bool $confirmed): string
    {
        if ($confirmed) {
            return 'success';
        }

        return match ($status) {
            'rechazado', 'fallido', 'expirado' => 'danger',
            'comprobante_enviado', 'pagado' => 'review',
            default => 'pending',
        };
    }

    private function currencySymbol(string $currency): string
    {
        return $currency === 'USD' ? '$' : 'S/';
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'bank_transfer' => 'Transferencia bancaria',
            'yape_plin' => 'Yape / Plin',
            'card' => 'Tarjeta de crédito o débito · Mercado Pago',
            'direct_coordination' => 'Coordinación directa',
            default => 'Método no especificado',
        };
    }

    private function formatWhatsAppMessage(
        string $reference,
        string $amount,
        string $currency,
        ?string $method,
        string $status,
        bool $confirmed,
        string $documentInfo,
        string $confirmationUrl,
    ): string {
        return 'Hola, consulto por mi comprobante informativo de DigitalPyme.'
            .' Referencia: '.$reference.'. Monto: '.$this->currencySymbol($currency).' '
            .number_format((float) $amount, 2, '.', ',').' '.$currency.'.'
            .' Documento: '.$documentInfo.'.'
            .' Método: '.$this->paymentMethodLabel($method).'. Estado: '
            .$this->statusLabel($status, $confirmed).'.'
            .' Consulta: '.$confirmationUrl;
    }

    private function whatsappUrl(string $message): ?string
    {
        $phone = (string) preg_replace('/\D+/', '', (string) SiteSetting::publicSettings()['contact']['whatsapp_phone']);
        if (strlen($phone) === 9 && str_starts_with($phone, '9')) {
            $phone = '51'.$phone;
        }
        if (! preg_match('/^\d{8,15}$/', $phone)) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }

    private function pdfResponse(string $pdf, string $filename): Response
    {
        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function confirmationView(array $data): Response
    {
        return response()
            ->view('payments.confirmation', $data)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
