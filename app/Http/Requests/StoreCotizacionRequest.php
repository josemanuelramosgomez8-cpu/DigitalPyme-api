<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCotizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activeService = fn () => Rule::exists('servicios', 'id')
            ->where(fn ($query) => $query->where('activo', true));

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'empresa' => ['nullable', 'string', 'max:255'],
            'contacto' => ['required', 'string', 'max:255'],
            'mensaje' => ['nullable', 'string', 'max:2000'],
            'servicio_id' => ['nullable', 'required_without:servicios_ids', 'integer', $activeService()],
            'servicios_ids' => ['nullable', 'required_without:servicio_id', 'array', 'min:1', 'max:20'],
            'servicios_ids.*' => ['required', 'integer', 'distinct', $activeService()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('servicio_id') && $this->filled('servicios_ids')) {
                $validator->errors()->add('servicios_ids', 'Envía un servicio único o una lista de servicios, no ambos.');
            }

            $contacto = trim((string) $this->input('contacto'));
            if (
                $contacto !== ''
                && filter_var($contacto, FILTER_VALIDATE_EMAIL) === false
                && preg_match('/^[0-9+()\s.-]{7,20}$/D', $contacto) !== 1
            ) {
                $validator->errors()->add('contacto', 'Ingresa un correo electrónico o un teléfono válido.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'empresa.max' => 'La empresa no puede superar los 255 caracteres.',
            'contacto.required' => 'El correo electrónico o teléfono de contacto es obligatorio.',
            'contacto.max' => 'El contacto no puede superar los 255 caracteres.',
            'mensaje.max' => 'El mensaje no puede superar los 2000 caracteres.',
            'servicio_id.required_without' => 'Selecciona un servicio o envía una lista de servicios.',
            'servicio_id.exists' => 'El servicio seleccionado no está disponible.',
            'servicios_ids.required_without' => 'Selecciona un servicio o envía una lista de servicios.',
            'servicios_ids.array' => 'Los servicios deben enviarse como una lista.',
            'servicios_ids.min' => 'Selecciona al menos un servicio.',
            'servicios_ids.max' => 'Puedes seleccionar hasta 20 servicios.',
            'servicios_ids.*.distinct' => 'No repitas servicios en la lista.',
            'servicios_ids.*.exists' => 'Uno o más servicios seleccionados no están disponibles.',
        ];
    }
}
