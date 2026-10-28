<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

#[Fillable(['nombre', 'ruta', 'mime', 'tamano'])]
class Adjunto extends Model
{
    use BelongsToTenant;

    protected $table = 'adjuntos';

    protected static function booted(): void
    {
        static::deleted(fn (Adjunto $adjunto) => Storage::disk('local')->delete($adjunto->ruta));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tamano' => 'integer',
        ];
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function esImagen(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Enlace temporal para ver el archivo sin enviar el token (sirve para
     * <Image> y para abrirlo en el navegador del celular).
     */
    public function urlFirmada(): string
    {
        return URL::temporarySignedRoute('adjuntos.archivo', now()->addMinutes(30), ['adjunto' => $this->id]);
    }
}
