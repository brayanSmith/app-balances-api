<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoriaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:ingreso,gasto'],
            'nombre' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categorias')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->where('tipo', $this->input('tipo'))
                    ->ignore($this->route('categoria')),
            ],
            'icono' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya tienes una categoría con ese nombre.',
        ];
    }
}
