<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportacionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'archivo' => $this->archivo,
            'filas' => $this->filas,
            'importados' => $this->importados,
            'duplicados' => $this->duplicados,
            'omitidos' => $this->omitidos,
            'creada' => $this->created_at->toIso8601String(),
        ];
    }
}
