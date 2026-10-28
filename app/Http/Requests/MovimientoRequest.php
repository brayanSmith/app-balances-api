<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovimientoRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'descripcion' => ['required', 'string', 'max:255'],
            // Una categoría del mismo tenant y del mismo tipo (gasto/ingreso).
            'categoria_id' => [
                'nullable',
                'integer',
                Rule::exists('categorias', 'id')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->where('tipo', $this->input('tipo')),
            ],
            'fecha' => ['required', 'date_format:Y-m-d'],
            // Id generado por la app (sin conexión): reenviarlo no duplica el movimiento.
            'cliente_id' => ['nullable', 'uuid'],

            // Programación: "repetir cada {intervalo} {frecuencia}" desde la fecha.
            // Al editar, si no se envía "programado", la programación no se toca.
            'programado' => ['sometimes', 'boolean'],
            'intervalo' => ['nullable', 'required_if_accepted:programado', 'integer', 'min:1', 'max:365'],
            'frecuencia' => ['nullable', 'required_if_accepted:programado', 'in:dias,semanas,meses,anios'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after:fecha'],
            // Al editar uno generado: los próximos se crean con estos datos.
            'actualizar_programado' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datosMovimiento(): array
    {
        return $this->safe()->only(['tipo', 'monto', 'descripcion', 'categoria_id', 'fecha']);
    }

    /**
     * @return array<string, mixed>
     */
    public function reglaProgramacion(): array
    {
        return $this->safe()->only(['intervalo', 'frecuencia', 'fecha_fin']);
    }
}
