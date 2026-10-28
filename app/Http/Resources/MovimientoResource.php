<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $programacion = $this->programacion;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'monto' => (float) $this->monto,
            'descripcion' => $this->descripcion,
            'categoria' => $this->categoria ? new CategoriaResource($this->categoria) : null,
            'fecha' => $this->fecha->toDateString(),
            // Si es el pago de un programado: el vencimiento que cubre.
            'fecha_programada' => $this->fecha_programada?->toDateString(),
            'adjuntos' => AdjuntoResource::collection($this->whenLoaded('adjuntos')),
            'adjuntos_count' => $this->adjuntos_count
                ?? ($this->relationLoaded('adjuntos') ? $this->adjuntos->count() : 0),
            'programado' => $programacion !== null,
            'programacion' => $programacion ? [
                'id' => $programacion->id,
                'intervalo' => $programacion->intervalo,
                'frecuencia' => $programacion->frecuencia,
                'fecha_fin' => $programacion->fecha_fin?->toDateString(),
                'proxima_fecha' => $programacion->proxima_fecha->toDateString(),
                'activo' => $programacion->activo,
                'es_base' => $programacion->movimiento_base_id === $this->id,
            ] : null,
        ];
    }
}
