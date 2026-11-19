<?php

namespace App\Http\Requests;

use App\Models\StoreOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->client !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:store_products,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
            'metodo_envio' => ['required', Rule::in(['estandar', 'express', 'same_day'])],
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
            'acepta_politicas' => ['required', 'accepted'],
        ];
    }
}
