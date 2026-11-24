<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->client !== null;
    }

    public function rules(): array
    {
        return [
            'metodo_envio' => ['required', Rule::in(['recojo', 'estandar', 'express', 'same_day'])],
            'ubicacion_entrega' => ['required', 'string', 'max:120'],
            'currency' => ['sometimes', 'required', Rule::in(['PEN', 'USD'])],
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
