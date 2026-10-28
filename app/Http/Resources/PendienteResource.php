<?php

namespace App\Http\Resources;

use App\Models\Programacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un programado con su vencimiento pendiente (vencido, hoy o próximo).
 *
 * @mixin Programacion
 */
class PendienteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dias = (int) today()->diffInDays($this->proxima_fecha, false);

        return [
            'id' => $this->id,
            'tipo' => $this->base->tipo,
            'descripcion' => $this->base->descripcion,
            'monto' => (float) $this->base->monto, // estimado: el del último pago
            'categoria' => $this->base->categoria ? new CategoriaResource($this->base->categoria) : null,
            'vence' => $this->proxima_fecha->toDateString(),
            'dias' => $dias, // negativo = días de atraso
            'estado' => match (true) {
                $dias < 0 => 'vencido',
                $dias === 0 => 'hoy',
                default => 'proximo',
            },
            'periodos_vencidos' => $this->periodosVencidos(),
            'intervalo' => $this->intervalo,
            'frecuencia' => $this->frecuencia,
        ];
    }
}
