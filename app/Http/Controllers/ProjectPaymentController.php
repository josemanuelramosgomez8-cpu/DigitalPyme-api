<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectPayment;
use App\Models\StoreOrder;
use App\Support\PaymentReceiptNotifier;
use App\Support\PaymentReceiptPdf;
use App\Support\StoreOrderNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectPaymentController extends Controller
{
    public function clientIndex(Request $request, Project $project)
    {
        $this->ensureClientOwnsProject($request, $project);

        return response()->json([
            'payments' => $project->payments()
                ->with(['milestone:id,titulo', 'receipt:id,payment_id,original_name,tipo,mime_type,size,created_at'])
                ->latest()
                ->get(),
            'card_enabled' => filled(config('services.mercadopago.access_token')),
            'bank_transfer' => [
                'bank' => config('services.bank_transfer.bank'),
                'holder' => config('services.bank_transfer.holder'),
                'account' => config('services.bank_transfer.account'),
                'cci' => config('services.bank_transfer.cci'),
            ],
            'yape_plin' => [
                'enabled' => (bool) config('services.yape_plin.enabled') && filled(config('services.yape_plin.phone')),
                'phone' => config('services.yape_plin.phone'),
                'holder' => config('services.yape_plin.holder'),
                'qr_url' => config('services.yape_plin.qr_url'),
            ],
        ]);
    }

    public function startTransfer(Request $request, Project $project)
    {
        $this->ensureClientOwnsProject($request, $project);
        $datos = $request->validate([
            'method' => ['sometimes', Rule::in(ProjectPayment::MANUAL_METHODS)],
            'milestone_id' => [
                'nullable',
                'integer',
                Rule::exists('project_milestones', 'id')->where('project_id', $project->id),
            ],
        ]);

        $method = $datos['method'] ?? 'bank_transfer';
        if ($method === 'yape_plin' && (
            ! config('services.yape_plin.enabled')
            || blank(config('services.yape_plin.phone'))
        )) {
            return response()->json([
                'message' => 'El pago con Yape/Plin aún no está habilitado. Configura el número de destino en el servidor.',
                'code' => 'yape_plin_not_configured',
            ], 503);
        }

        $payment = $this->createPayment($project, $datos['milestone_id'] ?? null, $method);

        return response()->json([
            'mensaje' => $method === 'yape_plin'
                ? 'Pago registrado. Realiza el envío por Yape/Plin y adjunta el comprobante para que el administrador lo revise.'
                : 'Pago registrado. Adjunta el comprobante de transferencia para que el administrador lo revise.',
            'payment' => $payment,
        ], 201);
    }

    public function startCardPayment(Request $request, Project $project)
    {
        $this->ensureClientOwnsProject($request, $project);
        $datos = $request->validate([
            'milestone_id' => [
                'nullable',
                'integer',
                Rule::exists('project_milestones', 'id')->where('project_id', $project->id),
            ],
        ]);

        $accessToken = config('services.mercadopago.access_token');
        if (blank($accessToken)) {
            return response()->json([
                'message' => 'El pago con tarjeta aún no está habilitado. Configura MERCADOPAGO_ACCESS_TOKEN con credenciales válidas del proveedor.',
                'code' => 'payment_provider_not_configured',
            ], 503);
        }

        $payment = $this->createPayment($project, $datos['milestone_id'] ?? null, 'card');
        $frontUrl = rtrim(config('services.mercadopago.return_url'), '/');
        $webhookUrl = config('services.mercadopago.webhook_url')
            ?: url('/api/payments/mercadopago/webhook');

        try {
            $preference = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.mercadopago.com/checkout/preferences', [
                    'items' => [[
                        'id' => (string) $payment->id,
                        'title' => $payment->milestone?->titulo ?? $project->nombre,
                        'quantity' => 1,
                        'unit_price' => (float) $payment->amount,
                        'currency_id' => $payment->currency,
                    ]],
                    'external_reference' => $payment->reference,
                    'notification_url' => $webhookUrl,
                    'back_urls' => [
                        'success' => $frontUrl.'/?pago=exitoso',
                        'pending' => $frontUrl.'/?pago=pendiente',
                        'failure' => $frontUrl.'/?pago=fallido',
                    ],
                    'auto_return' => 'approved',
                    'metadata' => [
                        'digitalpyme_payment_id' => $payment->id,
                    ],
                ]);

            $preference->throw();
            $checkoutUrl = $preference->json('init_point');
            $preferenceId = $preference->json('id');
            $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : null;

            if (
                ! is_string($checkoutUrl)
                || ! filter_var($checkoutUrl, FILTER_VALIDATE_URL)
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
                || ! is_string($checkoutHost)
                || preg_match('/(^|\.)mercadopago\.(com|com\.[a-z]{2}|[a-z]{2})$/D', $checkoutHost) !== 1
                || ! is_string($preferenceId)
                || $preferenceId === ''
            ) {
                throw new \UnexpectedValueException('Mercado Pago no devolvió una preferencia de pago válida.');
            }

            $payment->update([
                'provider_reference' => $preferenceId,
            ]);

            return response()->json([
                'payment' => $payment->refresh(),
                'checkout_url' => $checkoutUrl,
            ], 201);
        } catch (Throwable $exception) {
            $payment->update(['status' => 'fallido']);
            Log::error('No se pudo crear el checkout de Mercado Pago.', [
                'payment_id' => $payment->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo iniciar el pago con tarjeta. Inténtalo nuevamente o usa transferencia bancaria.',
            ], 502);
        }
    }

    public function mercadoPagoWebhook(
        Request $request,
        PaymentReceiptNotifier $receiptNotifier,
        StoreOrderNotifier $storeOrderNotifier,
    )
    {
        $providerPaymentId = $request->input('data.id') ?? $request->query('id');
        if (! is_string($providerPaymentId) && ! is_numeric($providerPaymentId)) {
            return response()->json(['mensaje' => 'Notificación recibida.']);
        }

        $accessToken = config('services.mercadopago.access_token');
        if (blank($accessToken)) {
            return response()->json(['message' => 'Proveedor de pagos no configurado.'], 503);
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->get('https://api.mercadopago.com/v1/payments/'.rawurlencode((string) $providerPaymentId));
            $response->throw();
            $providerPayment = $response->json();

            $reference = $providerPayment['external_reference'] ?? null;
            if (! is_string($reference)) {
                return response()->json(['mensaje' => 'Notificación recibida.']);
            }

            $result = DB::transaction(function () use ($reference, $providerPayment, $providerPaymentId) {
                $payment = ProjectPayment::where('reference', $reference)
                    ->where('method', 'card')
                    ->lockForUpdate()
                    ->first();
                if (! $payment) {
                    $order = StoreOrder::where('provider_reference', $reference)
                        ->lockForUpdate()
                        ->first();
                    if (! $order || $order->payment_method !== 'card' || $order->payment_status !== 'pendiente') {
                        return 'ignored';
                    }

                    $providerAmount = isset($providerPayment['transaction_amount'])
                        ? (int) round((float) $providerPayment['transaction_amount'] * 100)
                        : -1;
                    $expectedAmount = (int) round((float) $order->total * 100);
                    if (($providerPayment['currency_id'] ?? null) !== 'PEN' || $providerAmount !== $expectedAmount) {
                        return 'mismatch';
                    }

                    $providerStatus = $providerPayment['status'] ?? null;
                    if ($providerStatus === 'approved') {
                        $order->update([
                            'payment_status' => 'pagado',
                            'provider_payment_id' => (string) $providerPaymentId,
                            'paid_at' => now(),
                            'payment_due_at' => null,
                        ]);
                    } elseif (in_array($providerStatus, ['rejected', 'cancelled'], true)) {
                        $order->update([
                            'payment_status' => 'rechazado',
                            'provider_payment_id' => (string) $providerPaymentId,
                        ]);
                    }

                    return 'processed';
                }

                $providerAmount = isset($providerPayment['transaction_amount'])
                    ? (int) round((float) $providerPayment['transaction_amount'] * 100)
                    : -1;
                $expectedAmount = (int) round((float) $payment->amount * 100);

                if (($providerPayment['currency_id'] ?? null) !== $payment->currency || $providerAmount !== $expectedAmount) {
                    return 'mismatch';
                }

                if ($payment->status !== 'pendiente') {
                    return 'ignored';
                }

                $providerStatus = $providerPayment['status'] ?? null;
                if ($providerStatus === 'approved') {
                    $payment->update([
                        'status' => 'pagado',
                        'provider_reference' => (string) $providerPaymentId,
                        'paid_at' => now(),
                    ]);
                } elseif (in_array($providerStatus, ['rejected', 'cancelled'], true)) {
                    $payment->update([
                        'status' => 'rechazado',
                        'provider_reference' => (string) $providerPaymentId,
                    ]);
                }

                return 'processed';
            });

            if ($result === 'mismatch') {
                Log::warning('Notificación de Mercado Pago con monto o moneda no coincidentes.', [
                    'payment_reference' => $reference,
                    'provider_payment_id' => $providerPaymentId,
                ]);
            }

            if ($result === 'processed') {
                $payment = ProjectPayment::where('reference', $reference)
                    ->where('status', 'pagado')
                    ->first();

                if ($payment) {
                    $receiptNotifier->send($payment);
                } else {
                    $order = StoreOrder::where('provider_reference', $reference)
                        ->where('payment_status', 'pagado')
                        ->first();

                    if ($order) {
                        $storeOrderNotifier->send($order);
                    }
                }
            }

            return response()->json(['mensaje' => 'Notificación recibida.']);
        } catch (Throwable $exception) {
            Log::error('No se pudo verificar una notificación de Mercado Pago.', [
                'provider_payment_id' => (string) $providerPaymentId,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'No se pudo verificar el pago.'], 502);
        }
    }

    public function adminIndex(Request $request)
    {
        $datos = $request->validate([
            'status' => ['nullable', Rule::in(ProjectPayment::STATUSES)],
        ]);

        return response()->json(
            ProjectPayment::query()
                ->with([
                    'project:id,client_id,nombre',
                    'project.client:id,nombre,empresa',
                    'milestone:id,titulo',
                    'receipt:id,payment_id,original_name,tipo,mime_type,size,created_at',
                ])
                ->when($datos['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->latest()
                ->get()
        );
    }

    public function adminUpdateStatus(Request $request, ProjectPayment $payment, PaymentReceiptNotifier $receiptNotifier)
    {
        $datos = $request->validate([
            'status' => ['required', Rule::in(['pagado', 'rechazado'])],
        ]);

        $updatedPayment = DB::transaction(function () use ($payment, $datos) {
            $lockedPayment = ProjectPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedPayment->method, ProjectPayment::MANUAL_METHODS, true) || $lockedPayment->status !== 'comprobante_enviado') {
                throw ValidationException::withMessages([
                    'payment' => 'Solo se pueden revisar pagos manuales con comprobante enviado.',
                ]);
            }

            if (! $lockedPayment->receipt()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'No se puede verificar un pago manual sin comprobante adjunto.',
                ]);
            }

            $lockedPayment->update([
                'status' => $datos['status'],
                'paid_at' => $datos['status'] === 'pagado' ? now() : null,
            ]);

            return $lockedPayment->refresh();
        });

        $receiptEmailSent = $datos['status'] === 'pagado'
            ? $receiptNotifier->send($updatedPayment)
            : null;

        return response()->json([
            ...$updatedPayment->toArray(),
            ...($receiptEmailSent === null ? [] : ['receipt_email_sent' => $receiptEmailSent]),
        ]);
    }

    public function clientReceipt(Request $request, Project $project, ProjectPayment $payment)
    {
        $this->ensureClientOwnsProject($request, $project);
        abort_unless($payment->project_id === $project->id && $payment->status === 'pagado' && $payment->paid_at, 404);

        $pdf = PaymentReceiptPdf::render($payment);
        $filename = 'recibo-digitalpyme-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT).'.pdf';

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    private function createPayment(Project $project, ?int $milestoneId, string $method): ProjectPayment
    {
        return DB::transaction(function () use ($project, $milestoneId, $method) {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! $lockedProject->cotizacion || $lockedProject->cotizacion->estado !== 'aceptada') {
                throw ValidationException::withMessages([
                    'project' => 'Solo se pueden pagar proyectos con una cotización aceptada.',
                ]);
            }

            $milestone = null;
            if ($milestoneId !== null) {
                $milestone = ProjectMilestone::whereKey($milestoneId)
                    ->where('project_id', $lockedProject->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($milestone->estado !== 'aprobado' || $milestone->monto === null || (float) $milestone->monto <= 0) {
                    throw ValidationException::withMessages([
                        'milestone_id' => 'Solo se pueden pagar hitos aprobados con un monto válido.',
                    ]);
                }

                $amount = (float) $milestone->monto;
            } else {
                $amount = (float) $lockedProject->cotizacion->monto;
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto del proyecto debe ser mayor que cero.',
                ]);
            }

            $activeStatuses = ['pendiente', 'comprobante_enviado', 'pagado'];
            $existingPayments = $lockedProject->payments()
                ->whereIn('status', $activeStatuses)
                ->get(['id', 'milestone_id', 'amount']);

            $hasExistingFullPayment = $existingPayments->contains(fn (ProjectPayment $existing) => $existing->milestone_id === null);
            $alreadyPaidForMilestone = $existingPayments->contains(
                fn (ProjectPayment $existing) => $existing->milestone_id === $milestoneId
            );
            $quoteAmountCents = (int) round((float) $lockedProject->cotizacion->monto * 100);
            $existingAmountCents = $existingPayments->sum(
                fn (ProjectPayment $existing) => (int) round((float) $existing->amount * 100)
            );
            $paymentAmountCents = (int) round($amount * 100);

            if (
                $alreadyPaidForMilestone
                || ($milestoneId === null && $existingPayments->isNotEmpty())
                || ($milestoneId !== null && $hasExistingFullPayment)
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'Ya existe un pago vigente para este proyecto o hito.',
                ]);
            }

            if ($milestoneId !== null && $existingAmountCents + $paymentAmountCents > $quoteAmountCents) {
                throw ValidationException::withMessages([
                    'milestone_id' => 'El total de los pagos por hitos superaría el presupuesto aceptado.',
                ]);
            }

            return ProjectPayment::create([
                'reference' => (string) Str::uuid(),
                'project_id' => $lockedProject->id,
                'milestone_id' => $milestone?->id,
                'method' => $method,
                'provider' => $method === 'card' ? 'mercadopago' : null,
                'amount' => $amount,
                'currency' => 'PEN',
                'status' => 'pendiente',
            ]);
        });
    }

    private function ensureClientOwnsProject(Request $request, Project $project): void
    {
        abort_unless($project->client_id === $request->user()->client->id, 404);
    }
}
