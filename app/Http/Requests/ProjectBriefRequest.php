<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answers.objetivo' => ['required', 'string', 'max:2000'],
            'answers.publico' => ['required', 'string', 'max:2000'],
            'answers.tono' => ['nullable', 'string', 'max:1000'],
            'answers.referencias' => ['nullable', 'string', 'max:2000'],
            'answers.funcionalidades' => ['nullable', 'array', 'max:20'],
            'answers.funcionalidades.*' => ['required', 'string', 'max:160'],
        ];
    }
}
