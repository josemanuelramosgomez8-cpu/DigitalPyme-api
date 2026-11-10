<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Models\StockMovement;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreReturn;
use App\Models\StoreCoupon;
use App\Support\StoreOrderNotifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreLogisticsController extends Controller
{
    private const SHIPPING_KEYS = ['estandar', 'express', 'same_day'];

    public function publicProducts()
    {
        return response()->json(StoreProduct::query()
            ->where('activo', true)
            ->where('stock', '>', 0)
            ->orderBy('nombre')
            ->get(['id', 'sku', 'nombre', 'descripcion', 'categoria', 'precio', 'stock', 'image_path'])
            ->map(fn (StoreProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'nombre' => $product->nombre,
                'descripcion' => $product->descripcion,
                'categoria' => $product->categoria,
                'precio' => $product->precio,
                'stock' => $product->stock,
                'imagen_url' => $product->image_path ? url("/api/store/products/{$product->id}/image") : null,
            ]));
    }

    public function publicProductImage(StoreProduct $storeProduct)
    {
        abort_unless($storeProduct->image_path, 404);
        abort_unless(Storage::disk('uploads')->exists($storeProduct->image_path), 404, 'No se encontró la imagen.');

        return Storage::disk('uploads')->response($storeProduct->image_path, null, [
            'Content-Type' => $storeProduct->image_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ], 'inline');
    }

    public function shippingOptions()
    {
        $settings = SiteSetting::publicSettings()['coverage'];

        return response()->json([
            'methods' => $settings['shipping_methods'],
            'zones' => $settings['shipping_zones'],
            'free_shipping_threshold' => $settings['free_shipping_threshold'],
        ]);
    }

    public function paymentOptions()
    {
        return response()->json($this->configuredPaymentOptions());
    }

    private function configuredPaymentOptions(): array
    {
        $bankTransfer = config('services.bank_transfer');
        $yapePlin = config('services.yape_plin');
        $bankEnabled = filled($bankTransfer['account']) || filled($bankTransfer['cci']);
        $yapeEnabled = (bool) $yapePlin['enabled'] && filled($yapePlin['phone']);

        return [
            'card_enabled' => filled(config('services.mercadopago.access_token')),
            'card_environment' => config('services.mercadopago.environment', 'sandbox'),
            'igv_rate' => 18,
            'bank_transfer' => [
                'enabled' => $bankEnabled,
                'bank' => $bankEnabled ? $bankTransfer['bank'] : null,
                'holder' => $bankEnabled ? $bankTransfer['holder'] : null,
                'account' => $bankEnabled ? $bankTransfer['account'] : null,
                'cci' => $bankEnabled ? $bankTransfer['cci'] : null,
            ],
            'yape_plin' => [
                'enabled' => $yapeEnabled,
                'phone' => $yapeEnabled ? $yapePlin['phone'] : null,
                'holder' => $yapeEnabled ? $yapePlin['holder'] : null,
                'qr_url' => $yapeEnabled ? $yapePlin['qr_url'] : null,
            ],
        ];
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:store_products,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
            'metodo_envio' => ['required', Rule::in(self::SHIPPING_KEYS)],
            'payment_method' => ['required', Rule::in(StoreOrder::PAYMENT_METHODS)],
            'ubicacion_entrega' => ['required', 'string', 'max:120'],
            'direccion_entrega' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'regex:/^[0-9+()\s.-]{7,20}$/'],
            'tipo_comprobante' => ['required', Rule::in(['boleta', 'factura'])],
            'tipo_documento' => ['required', Rule::in(['dni', 'ruc'])],
            'numero_documento' => ['required', 'string', 'max:20'],
            'empresa_facturacion' => ['required_if:tipo_comprobante,factura', 'nullable', 'string', 'max:255'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'tipo_operacion' => ['sometimes', Rule::in(['in-house', '3PL'])],
            'operador_logistico' => ['required_if:tipo_operacion,3PL', 'nullable', 'string', 'max:120'],
        ]);

        $documentLength = $data['tipo_documento'] === 'dni' ? 8 : 11;
        if (! ctype_digit($data['numero_documento']) || strlen($data['numero_documento']) !== $documentLength) {
            throw ValidationException::withMessages([
                'numero_documento' => $data['tipo_documento'] === 'dni'
                    ? 'El DNI debe tener 8 dígitos.'
                    : 'El RUC debe tener 11 dígitos.',
            ]);
        }
        if ($data['tipo_comprobante'] === 'factura' && $data['tipo_documento'] !== 'ruc') {
            throw ValidationException::withMessages([
                'tipo_documento' => 'Para emitir factura debes ingresar un RUC.',
            ]);
        }

        $coverage = SiteSetting::publicSettings()['coverage'];
        $shippingMethod = collect($coverage['shipping_methods'])->firstWhere('key', $data['metodo_envio']);
        if (!$shippingMethod || !$shippingMethod['enabled']) {
            throw ValidationException::withMessages(['metodo_envio' => 'La opción de envío seleccionada no está disponible.']);
        }
        $shippingZone = collect($coverage['shipping_zones'])->first(
            fn (array $zone) => Str::lower(trim($zone['location'])) === Str::lower(trim($data['ubicacion_entrega']))
        );
        $standardShippingMethod = collect($coverage['shipping_methods'])->firstWhere('key', 'estandar');
        if ($data['metodo_envio'] === 'same_day' && !Str::contains(Str::lower($data['ubicacion_entrega']), 'lima')) {
            throw ValidationException::withMessages(['metodo_envio' => 'El envío Same-day solo está disponible para Lima.']);
        }
        $paymentOptions = $this->configuredPaymentOptions();
        $paymentMethodEnabled = $data['payment_method'] === 'card'
            ? $paymentOptions['card_enabled']
            : $paymentOptions[$data['payment_method']]['enabled'];
        if (! $paymentMethodEnabled) {
            throw ValidationException::withMessages([
                'payment_method' => 'El método de pago seleccionado todavía no está configurado.',
            ]);
        }

        $order = DB::transaction(function () use ($request, $data, $coverage, $shippingMethod, $shippingZone, $standardShippingMethod) {
            $lockedProducts = [];
            $subtotalCents = 0;
            $productIds = array_column($data['items'], 'product_id');
            sort($productIds, SORT_NUMERIC);
            $productsById = [];
            foreach ($productIds as $productId) {
                $productsById[$productId] = StoreProduct::query()
                    ->lockForUpdate()
                    ->findOrFail($productId);
            }

            foreach ($data['items'] as $item) {
                $product = $productsById[$item['product_id']];
                if (!$product->activo || $product->stock < $item['cantidad']) {
                    throw ValidationException::withMessages([
                        'items' => "Stock insuficiente para {$product->nombre}.",
                    ]);
                }
                $unitPriceCents = self::amountToCents($product->precio);
                $lockedProducts[] = [$product, $item['cantidad'], $unitPriceCents];
                $subtotalCents += $unitPriceCents * $item['cantidad'];
            }

            $coupon = null;
            $discountCents = 0;
            if (filled($data['coupon_code'] ?? null)) {
                $coupon = StoreCoupon::query()
                    ->where('code', strtoupper(trim($data['coupon_code'])))
                    ->lockForUpdate()
                    ->first();

                if (! $coupon || ! $coupon->isAvailable()) {
                    throw ValidationException::withMessages([
                        'coupon_code' => 'El cupón no existe, venció o no está activo.',
                    ]);
                }

                $discountCents = StoreCouponController::discountCents($coupon, $subtotalCents);
            }

            $zoneBaseCents = $shippingZone
                ? self::amountToCents($shippingZone['fee'])
                : self::amountToCents($standardShippingMethod['cost'] ?? $shippingMethod['cost']);
            $standardCostCents = self::amountToCents($standardShippingMethod['cost'] ?? $shippingMethod['cost']);
            $methodSurchargeCents = self::amountToCents($shippingMethod['cost']) - $standardCostCents;
            $discountedSubtotalCents = $subtotalCents - $discountCents;
            $shippingCostCents = $discountedSubtotalCents >= self::amountToCents($coverage['free_shipping_threshold'])
                ? 0
                : max(0, $zoneBaseCents + $methodSurchargeCents);
            $taxableBaseCents = $discountedSubtotalCents + $shippingCostCents;
            $igvCents = intdiv(($taxableBaseCents * 18) + 50, 100);
            $client = $request->user()->client;
            $client->update([
                'telefono' => $data['telefono'],
                'tipo_documento' => $data['tipo_documento'],
                'numero_documento' => $data['numero_documento'],
                'tipo_comprobante' => $data['tipo_comprobante'],
                'empresa' => $data['empresa_facturacion'] ?? $client->empresa,
                'direccion_entrega' => $data['direccion_entrega'],
                'ubicacion_entrega' => $data['ubicacion_entrega'],
            ]);
            $order = StoreOrder::create([
                'client_id' => $client->id,
                'nombre_cliente' => $client->nombre,
                'email_cliente' => $request->user()->email,
                'telefono_cliente' => $data['telefono'],
                'tipo_comprobante' => $data['tipo_comprobante'],
                'tipo_documento' => $data['tipo_documento'],
                'numero_documento' => $data['numero_documento'],
                'empresa_facturacion' => $data['empresa_facturacion'] ?? null,
                'estado' => 'recibido',
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pendiente',
                'payment_due_at' => now()->addHours(24),
                'tipo_operacion' => $data['tipo_operacion'] ?? 'in-house',
                'operador_logistico' => ($data['tipo_operacion'] ?? 'in-house') === '3PL' ? $data['operador_logistico'] : null,
                'metodo_envio' => $shippingMethod['key'],
                'ubicacion_entrega' => $data['ubicacion_entrega'],
                'direccion_entrega' => $data['direccion_entrega'],
                'subtotal' => self::centsToAmount($subtotalCents),
                'costo_envio' => self::centsToAmount($shippingCostCents),
                'discount_amount' => self::centsToAmount($discountCents),
                'coupon_code' => $coupon?->code,
                'igv_rate' => 18,
                'igv_amount' => self::centsToAmount($igvCents),
                'total' => self::centsToAmount($taxableBaseCents + $igvCents),
            ]);

            foreach ($lockedProducts as [$product, $quantity, $unitPriceCents]) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'nombre' => $product->nombre,
                    'cantidad' => $quantity,
                    'precio_unitario' => self::centsToAmount($unitPriceCents),
                    'subtotal' => self::centsToAmount($unitPriceCents * $quantity),
                ]);
                $product->stock -= $quantity;
                $product->save();
                StockMovement::create([
                    'product_id' => $product->id,
                    'order_id' => $order->id,
                    'tipo' => 'salida',
                    'cantidad' => $quantity,
                    'motivo' => "Venta del pedido #{$order->id}",
                ]);
            }

            return $order;
        });

        $emailSent = app(StoreOrderNotifier::class)->send($order);
        $response = $order->load([
            'items' => fn ($query) => $query->orderBy('id'),
        ])->toArray();
        $response['email_enviado'] = $emailSent;

        return response()->json($response, 201);
    }

    public function startCardPayment(Request $request, StoreOrder $storeOrder)
    {
        abort_unless($storeOrder->client_id === $request->user()->client->id, 404);
        abort_unless($storeOrder->payment_method === 'card', 409, 'El pedido no está configurado para pagar con tarjeta.');

        if (! in_array($storeOrder->payment_status, ['pendiente', 'rechazado'], true)) {
            throw ValidationException::withMessages([
                'payment' => 'Este pedido ya no admite un nuevo intento de pago.',
            ]);
        }
        if ($storeOrder->payment_status === 'pendiente' && filled($storeOrder->provider_checkout_url)) {
            return response()->json([
                'checkout_url' => $storeOrder->provider_checkout_url,
                'payment_status' => 'pendiente',
            ]);
        }

        $accessToken = config('services.mercadopago.access_token');
        if (blank($accessToken)) {
            return response()->json([
                'message' => 'El pago con tarjeta aún no está habilitado. Configura MERCADOPAGO_ACCESS_TOKEN en el servidor.',
                'code' => 'payment_provider_not_configured',
            ], 503);
        }

        $storeOrder->load('items');
        $externalReference = "store-order-{$storeOrder->id}-".Str::uuid();
        $frontUrl = rtrim(config('services.mercadopago.return_url'), '/').'/tienda?pedido='.$storeOrder->id;
        $webhookUrl = config('services.mercadopago.webhook_url')
            ?: url('/api/payments/mercadopago/webhook');
        $items = [[
            'id' => "store-order-{$storeOrder->id}",
            'title' => "Pedido #{$storeOrder->id} (productos, envío e IGV)",
            'quantity' => 1,
            'unit_price' => (float) $storeOrder->total,
            'currency_id' => 'PEN',
        ]];

        try {
            $preference = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.mercadopago.com/checkout/preferences', [
                    'items' => $items,
                    'external_reference' => $externalReference,
                    'notification_url' => $webhookUrl,
                    'back_urls' => [
                        'success' => $frontUrl.'&pago=exitoso',
                        'pending' => $frontUrl.'&pago=pendiente',
                        'failure' => $frontUrl.'&pago=fallido',
                    ],
                    'auto_return' => 'approved',
                    'metadata' => [
                        'store_order_id' => $storeOrder->id,
                    ],
                    'expires' => true,
                    'expiration_date_from' => now()->toIso8601String(),
                    'expiration_date_to' => $storeOrder->payment_due_at->toIso8601String(),
                ]);
            $preference->throw();

            $checkoutUrl = config('services.mercadopago.environment', 'sandbox') === 'sandbox'
                ? $preference->json('sandbox_init_point')
                : $preference->json('init_point');
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

            $storeOrder->update([
                'provider_reference' => $externalReference,
                'provider_preference_id' => $preferenceId,
                'provider_payment_id' => null,
                'provider_checkout_url' => $checkoutUrl,
                'payment_status' => 'pendiente',
            ]);

            return response()->json([
                'checkout_url' => $checkoutUrl,
                'payment_status' => 'pendiente',
            ], 201);
        } catch (Throwable $exception) {
            Log::error('No se pudo crear el checkout de Mercado Pago para una orden.', [
                'order_id' => $storeOrder->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo iniciar el pago con tarjeta. Inténtalo nuevamente.',
            ], 502);
        }
    }

    private static function amountToCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function centsToAmount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function adminIndex(Request $request)
    {
        $data = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'products_page' => ['sometimes', 'integer', 'min:1'],
            'movements_page' => ['sometimes', 'integer', 'min:1'],
            'orders_page' => ['sometimes', 'integer', 'min:1'],
            'returns_page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
        $periodMovements = StockMovement::query()
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
        $activePeriodMovements = (clone $periodMovements)
            ->whereHas('product', fn ($query) => $query->where('activo', true));
        $unitsSold = (clone $activePeriodMovements)
            ->where('tipo', 'salida')
            ->whereNotNull('order_id')
            ->whereHas('order', fn ($query) => $query->where('payment_status', 'pagado'))
            ->sum('cantidad');
        $unitsOut = (clone $activePeriodMovements)->where('tipo', 'salida')->sum('cantidad');
        $unitsReceived = (clone $activePeriodMovements)->where('tipo', 'entrada')->sum('cantidad');
        $currentStock = StoreProduct::where('activo', true)->sum('stock');
        $openingStock = max(0, $currentStock - $unitsReceived + $unitsOut);
        $averageStock = ($openingStock + $currentStock) / 2;
        $fastMovers = (clone $periodMovements)
            ->where('tipo', 'salida')
            ->whereNotNull('order_id')
            ->whereHas('order', fn ($query) => $query->where('payment_status', 'pagado'))
            ->whereHas('product', fn ($query) => $query->where('activo', true))
            ->with('product:id,sku,nombre,ubicacion_almacen')
            ->select('product_id')
            ->selectRaw('SUM(cantidad) as units_sold')
            ->groupBy('product_id')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'product_id' => $movement->product_id,
                'sku' => $movement->product?->sku,
                'nombre' => $movement->product?->nombre ?? 'Producto eliminado',
                'ubicacion_almacen' => $movement->product?->ubicacion_almacen,
                'units_sold' => (int) $movement->units_sold,
            ])
            ->values();
        $returnResolutions = StoreReturn::query()
            ->whereNotNull('resuelta_at')
            ->select('resolucion')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('resolucion')
            ->pluck('total', 'resolucion');
        $perPage = $data['per_page'] ?? 50;

        $products = StoreProduct::query()
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'products_page');
        $movements = StockMovement::with('product:id,sku,nombre')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'movements_page');
        $orders = StoreOrder::with(['client:id,nombre,empresa', 'items.product:id,ubicacion_almacen', 'returnRequest'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'orders_page');
        $returns = StoreReturn::with([
            'order:id,client_id,estado,total',
            'order.client:id,nombre,empresa',
            'order.items.product:id,ubicacion_almacen',
        ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'returns_page');

        return response()->json([
            'products' => $products->items(),
            'movements' => $movements->items(),
            'orders' => $orders->items(),
            'coupons' => StoreCoupon::query()->orderBy('code')->get(),
            'returns' => $returns->items(),
            'pagination' => [
                'products' => $this->paginationMetadata($products),
                'movements' => $this->paginationMetadata($movements),
                'orders' => $this->paginationMetadata($orders),
                'returns' => $this->paginationMetadata($returns),
            ],
            'metrics' => [
                'low_stock' => StoreProduct::where('stock', '>', 0)->whereColumn('stock', '<=', 'punto_reorden')->count(),
                'out_of_stock' => StoreProduct::where('stock', 0)->count(),
                'units_sold' => (int) $unitsSold,
                'inventory_rotation' => $averageStock > 0 ? round($unitsSold / $averageStock, 2) : 0,
                'fast_movers' => $fastMovers,
                'return_resolutions' => [
                    'reventa' => (int) ($returnResolutions['reventa'] ?? 0),
                    'reparacion' => (int) ($returnResolutions['reparacion'] ?? 0),
                    'desecho' => (int) ($returnResolutions['desecho'] ?? 0),
                    'reembolso' => (int) ($returnResolutions['reembolso'] ?? 0),
                ],
            ],
            'shipping' => SiteSetting::publicSettings()['coverage'],
        ]);
    }

    private function paginationMetadata(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', 'unique:store_products,sku'],
            'nombre' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:3000'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'precio' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'punto_reorden' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'ubicacion_almacen' => ['nullable', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $initialStock = (int) ($data['stock'] ?? 0);
        $product = DB::transaction(function () use ($request, $data, $initialStock) {
            $product = StoreProduct::create($data);
            if ($initialStock > 0) {
                $product->movements()->create([
                    'created_by' => $request->user()->id,
                    'tipo' => 'entrada',
                    'cantidad' => $initialStock,
                    'motivo' => 'Stock inicial del producto',
                ]);
            }

            return $product;
        });

        return response()->json($product, 201);
    }

    public function updateProduct(Request $request, StoreProduct $storeProduct)
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('store_products', 'sku')->ignore($storeProduct->id)],
            'nombre' => ['sometimes', 'required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:3000'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'precio' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'punto_reorden' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'ubicacion_almacen' => ['nullable', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
        ]);
        $storeProduct->update($data);

        return response()->json($storeProduct->refresh());
    }

    public function uploadProductImage(Request $request, StoreProduct $storeProduct)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $image = $request->file('image');
        $extension = $image->extension();
        $path = $image->storeAs(
            "store-products/{$storeProduct->id}",
            Str::uuid().'.'.$extension,
            'uploads',
        );
        if (! is_string($path) || $path === '') {
            abort(500, 'No se pudo guardar la imagen del producto.');
        }

        try {
            $previousPath = DB::transaction(function () use ($storeProduct, $image, $path) {
                $product = StoreProduct::query()->lockForUpdate()->findOrFail($storeProduct->id);
                $previousPath = $product->image_path;
                $product->update([
                    'image_path' => $path,
                    'image_mime_type' => $image->getMimeType() ?: 'application/octet-stream',
                ]);

                return $previousPath;
            });
        } catch (Throwable $exception) {
            Storage::disk('uploads')->delete($path);

            throw $exception;
        }

        if ($previousPath && ! Storage::disk('uploads')->delete($previousPath)) {
            Log::warning('No se pudo eliminar la imagen anterior del producto.', [
                'product_id' => $storeProduct->id,
                'path' => $previousPath,
            ]);
        }

        return response()->json([
            'mensaje' => 'Imagen del producto actualizada.',
            'imagen_url' => url("/api/store/products/{$storeProduct->id}/image"),
        ]);
    }

    public function deleteProductImage(StoreProduct $storeProduct)
    {
        if (! $storeProduct->image_path) {
            return response()->json(['mensaje' => 'El producto no tenía imagen.']);
        }

        $path = $storeProduct->image_path;
        DB::transaction(function () use ($storeProduct) {
            $product = StoreProduct::query()->lockForUpdate()->findOrFail($storeProduct->id);
            $product->update(['image_path' => null, 'image_mime_type' => null]);
        });

        if (! Storage::disk('uploads')->delete($path)) {
            Log::warning('No se pudo eliminar el archivo de imagen del producto.', [
                'product_id' => $storeProduct->id,
                'path' => $path,
            ]);
        }

        return response()->json(['mensaje' => 'Imagen del producto eliminada.']);
    }

    public function deleteProduct(StoreProduct $storeProduct)
    {
        $storeProduct->update(['activo' => false]);

        return response()->json(['mensaje' => 'Producto desactivado; se conservó el historial de inventario.']);
    }

    public function addStockMovement(Request $request, StoreProduct $storeProduct)
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(['entrada', 'salida'])],
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $product = DB::transaction(function () use ($request, $storeProduct, $data) {
            $product = StoreProduct::query()->lockForUpdate()->findOrFail($storeProduct->id);
            if ($data['tipo'] === 'salida' && $product->stock < $data['cantidad']) {
                throw ValidationException::withMessages(['cantidad' => 'La salida supera el stock disponible.']);
            }
            $product->movements()->create([
                'created_by' => $request->user()->id,
                ...$data,
            ]);
            $product->stock += $data['tipo'] === 'entrada' ? $data['cantidad'] : -$data['cantidad'];
            $product->save();

            return $product->refresh();
        });

        return response()->json($product);
    }

    public function updateOrder(Request $request, StoreOrder $storeOrder)
    {
        $data = $request->validate([
            'estado' => ['sometimes', 'required', Rule::in(StoreOrder::ESTADOS)],
            'tipo_operacion' => ['sometimes', 'required', Rule::in(['in-house', '3PL'])],
            'operador_logistico' => ['nullable', 'string', 'max:120'],
            'codigo_rastreo' => ['nullable', 'string', 'max:120'],
        ]);

        $order = DB::transaction(function () use ($storeOrder, $data) {
            $order = StoreOrder::query()->lockForUpdate()->findOrFail($storeOrder->id);
            if (isset($data['estado']) && $data['estado'] !== $order->estado) {
                if ($order->payment_status !== 'pagado') {
                    throw ValidationException::withMessages(['estado' => 'Confirma el pago antes de preparar o enviar el pedido.']);
                }
                $currentIndex = array_search($order->estado, StoreOrder::ESTADOS, true);
                $nextState = $currentIndex === false ? null : (StoreOrder::ESTADOS[$currentIndex + 1] ?? null);
                if ($data['estado'] !== $nextState) {
                    throw ValidationException::withMessages(['estado' => 'El pedido debe avanzar un estado a la vez.']);
                }
            }
            $operation = $data['tipo_operacion'] ?? $order->tipo_operacion;
            if ($operation === '3PL' && blank($data['operador_logistico'] ?? $order->operador_logistico)) {
                throw ValidationException::withMessages(['operador_logistico' => 'Indica el operador logístico cuando el pedido es 3PL.']);
            }
            if (($data['estado'] ?? null) === 'enviado' && blank($data['codigo_rastreo'] ?? $order->codigo_rastreo)) {
                throw ValidationException::withMessages(['codigo_rastreo' => 'Registra el código de rastreo antes de marcar el pedido como enviado.']);
            }

            if (($data['estado'] ?? null) === 'enviado') $data['enviado_at'] = now();
            if (($data['estado'] ?? null) === 'entregado') $data['entregado_at'] = now();
            if ($operation !== '3PL') $data['operador_logistico'] = null;
            $order->update($data);

            return $order;
        });

        $response = $order->load(['client:id,nombre,empresa', 'items', 'returnRequest'])->toArray();

        return response()->json($response);
    }

    public function uploadClientReceipt(Request $request, StoreOrder $storeOrder)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        $file = $request->file('file');
        $path = $file->store("store-orders/{$storeOrder->id}/receipts", 'uploads');
        if (! is_string($path) || $path === '') {
            abort(500, 'No se pudo guardar el comprobante.');
        }

        try {
            $previousPath = DB::transaction(function () use ($request, $storeOrder, $file, $path) {
                $order = StoreOrder::query()->lockForUpdate()->findOrFail($storeOrder->id);
                abort_unless($order->client_id === $request->user()->client->id, 404);
                if (! in_array($order->payment_method, ['bank_transfer', 'yape_plin'], true)) {
                    throw ValidationException::withMessages([
                        'file' => 'Los pagos con tarjeta no admiten comprobantes manuales.',
                    ]);
                }
                if (! in_array($order->payment_status, ['pendiente', 'rechazado'], true)) {
                    throw ValidationException::withMessages([
                        'file' => 'Este pedido no admite otro comprobante.',
                    ]);
                }

                $previousPath = $order->receipt_path;
                $order->update([
                    'receipt_path' => $path,
                    'receipt_original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
                    'receipt_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'receipt_size' => $file->getSize(),
                    'receipt_uploaded_at' => now(),
                    'payment_status' => 'comprobante_enviado',
                ]);

                return $previousPath;
            });
        } catch (Throwable $exception) {
            Storage::disk('uploads')->delete($path);

            throw $exception;
        }

        if ($previousPath && ! Storage::disk('uploads')->delete($previousPath)) {
            Log::warning('No se pudo eliminar el comprobante reemplazado del pedido.', [
                'order_id' => $storeOrder->id,
                'path' => $previousPath,
            ]);
        }

        return response()->json([
            'mensaje' => 'Comprobante enviado. El pago quedará pendiente hasta que lo verifiquemos.',
            'payment_status' => 'comprobante_enviado',
            'receipt_original_name' => $storeOrder->refresh()->receipt_original_name,
        ], 201);
    }

    public function updatePaymentStatus(Request $request, StoreOrder $storeOrder, StoreOrderNotifier $orderNotifier)
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['pagado', 'rechazado'])],
        ]);

        $order = DB::transaction(function () use ($storeOrder, $data) {
            $order = StoreOrder::query()->lockForUpdate()->findOrFail($storeOrder->id);
            if ($order->payment_status !== 'comprobante_enviado') {
                throw ValidationException::withMessages([
                    'payment_status' => 'Solo puedes revisar pedidos con comprobante enviado.',
                ]);
            }

            $order->update([
                'payment_status' => $data['payment_status'],
                'paid_at' => $data['payment_status'] === 'pagado' ? now() : null,
                'payment_due_at' => $data['payment_status'] === 'pagado' ? null : now()->addHours(24),
            ]);

            return $order;
        });

        $response = $order->load(['client:id,nombre,empresa', 'items', 'returnRequest'])->toArray();
        if ($data['payment_status'] === 'pagado') {
            $response['receipt_email_sent'] = $orderNotifier->send($order);
        }

        return response()->json($response);
    }

    public function clientReceipt(Request $request, StoreOrder $storeOrder)
    {
        abort_unless($storeOrder->client_id === $request->user()->client->id, 404);

        return $this->downloadReceipt($storeOrder);
    }

    public function adminReceipt(StoreOrder $storeOrder)
    {
        return $this->downloadReceipt($storeOrder);
    }

    private function downloadReceipt(StoreOrder $order)
    {
        abort_unless($order->receipt_path && Storage::disk('uploads')->exists($order->receipt_path), 404, 'No se encontró el comprobante.');

        return Storage::disk('uploads')->download($order->receipt_path, $order->receipt_original_name, [
            'Content-Type' => $order->receipt_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateReturn(Request $request, StoreReturn $storeReturn)
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(StoreReturn::ESTADOS)],
            'resolucion' => ['nullable', Rule::in(StoreReturn::RESOLUCIONES)],
            'notas_inspeccion' => [
                Rule::requiredIf(($request->input('estado') ?? null) === 'inspeccionada'),
                'nullable',
                'string',
                'min:5',
                'max:3000',
            ],
        ]);

        $return = DB::transaction(function () use ($request, $storeReturn, $data) {
            $return = StoreReturn::query()->lockForUpdate()->findOrFail($storeReturn->id);
            $currentIndex = array_search($return->estado, StoreReturn::ESTADOS, true);
            $nextState = $currentIndex === false ? null : (StoreReturn::ESTADOS[$currentIndex + 1] ?? null);
            if ($data['estado'] !== $nextState) {
                throw ValidationException::withMessages(['estado' => 'La devolución debe avanzar un estado a la vez.']);
            }
            if ($data['estado'] === 'resuelta' && empty($data['resolucion'])) {
                throw ValidationException::withMessages(['resolucion' => 'Selecciona la resolución de la devolución.']);
            }

            $return->update([
                ...$data,
                'inspeccionada_at' => $data['estado'] === 'inspeccionada' ? now() : $return->inspeccionada_at,
                'resuelta_at' => $data['estado'] === 'resuelta' ? now() : null,
            ]);
            if ($data['estado'] === 'resuelta' && $data['resolucion'] === 'reventa') {
                $order = $return->order()->with('items')->firstOrFail();
                $productIds = $order->items->pluck('product_id')->filter()->unique()->sort()->values();
                $productsById = [];
                foreach ($productIds as $productId) {
                    $product = StoreProduct::query()
                        ->lockForUpdate()
                        ->find($productId);
                    if ($product) {
                        $productsById[$productId] = $product;
                    }
                }

                foreach ($order->items as $item) {
                    if (!$item->product_id || !isset($productsById[$item->product_id])) {
                        continue;
                    }
                    $product = $productsById[$item->product_id];
                    $product->stock += $item->cantidad;
                    $product->save();
                    $product->movements()->create([
                        'created_by' => $request->user()->id,
                        'tipo' => 'entrada',
                        'cantidad' => $item->cantidad,
                        'motivo' => "Devolución #{$return->id} apta para reventa",
                    ]);
                }
            }

            return $return;
        });

        return response()->json($return->load(['order:id,client_id,estado,total', 'order.items']));
    }

    public function clientOrders(Request $request)
    {
        return response()->json($request->user()->client->storeOrders()
            ->with(['items', 'returnRequest', 'client:id,nombre,empresa'])
            ->latest()
            ->get());
    }

    public function clientReturn(Request $request, StoreOrder $storeOrder)
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:2000']]);
        $return = DB::transaction(function () use ($request, $storeOrder, $data) {
            $order = StoreOrder::query()->lockForUpdate()->findOrFail($storeOrder->id);
            abort_unless($order->client_id === $request->user()->client->id, 404);
            if ($order->estado !== 'entregado') {
                throw ValidationException::withMessages(['pedido' => 'Solo puedes solicitar la devolución de un pedido entregado.']);
            }
            if ($order->returnRequest()->exists()) {
                abort(409, 'Este pedido ya tiene una solicitud de devolución.');
            }

            return $order->returnRequest()->create($data);
        });

        return response()->json($return, 201);
    }
}
