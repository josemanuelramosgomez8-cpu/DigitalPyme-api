<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->client !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:store_products,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
            'communications_consent' => ['required', 'boolean'],
        ];
    }
}
