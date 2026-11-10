<?php

namespace App\Http\Controllers;

use App\Models\StoreCoupon;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCouponController extends Controller
{
    public function index()
    {
        return response()->json(StoreCoupon::query()->orderBy('code')->get());
    }

    public function validateForCheckout(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:store_products,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $coupon = StoreCoupon::query()
            ->where('code', strtoupper(trim($data['code'])))
            ->first();

        if (! $coupon || ! $coupon->isAvailable()) {
            throw ValidationException::withMessages([
                'coupon_code' => 'El cupón no existe, venció o no está activo.',
            ]);
        }

        $products = StoreProduct::query()
            ->whereIn('id', array_column($data['items'], 'product_id'))
            ->where('activo', true)
            ->get()
            ->keyBy('id');
        $subtotalCents = 0;

        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);
            if (! $product || $product->stock < $item['cantidad']) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los productos ya no está disponible en la cantidad seleccionada.',
                ]);
            }

            $subtotalCents += self::amountToCents($product->precio) * $item['cantidad'];
        }

        $discountCents = self::discountCents($coupon, $subtotalCents);

        return response()->json([
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'discount_amount' => self::centsToAmount($discountCents),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['code'] = strtoupper(trim($data['code']));

        return response()->json(StoreCoupon::create($data), 201);
    }

    public function update(Request $request, StoreCoupon $storeCoupon)
    {
        $data = $this->validatedData($request, $storeCoupon);
        $data['code'] = strtoupper(trim($data['code']));
        $storeCoupon->update($data);

        return response()->json($storeCoupon->refresh());
    }

    public function destroy(StoreCoupon $storeCoupon)
    {
        $storeCoupon->delete();

        return response()->json(['mensaje' => 'Cupón eliminado.']);
    }

    private function validatedData(Request $request, ?StoreCoupon $coupon = null): array
    {
        $maximumValue = $request->input('discount_type') === 'percentage' ? 100 : 99999999;

        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('store_coupons', 'code')->ignore($coupon),
            ],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                "max:{$maximumValue}",
            ],
            'active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
    }

    public static function discountCents(StoreCoupon $coupon, int $subtotalCents): int
    {
        $valueCents = self::amountToCents($coupon->discount_value);
        $discountCents = $coupon->discount_type === 'percentage'
            ? intdiv(($subtotalCents * $valueCents) + 5000, 10000)
            : $valueCents;

        return min($subtotalCents, $discountCents);
    }

    private static function amountToCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0', STR_PAD_RIGHT);
    }

    private static function centsToAmount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
