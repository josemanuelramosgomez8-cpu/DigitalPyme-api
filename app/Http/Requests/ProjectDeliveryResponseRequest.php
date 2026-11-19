<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectDeliveryResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->client?->id === $this->route('project')?->client_id;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'request_changes'])],
            'comments' => ['required_if:action,request_changes', 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
