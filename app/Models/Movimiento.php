<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Sincronizable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tipo', 'monto', 'descripcion', 'categoria_id', 'fecha'])]
class Movimiento extends Model
{
    use BelongsToTenant, Sincronizable;

    protected static function booted(): void
    {
        // Si se borra el movimiento base, la programación sigue con el más
        // reciente que quede; si era el único, la programación se elimina.
        static::deleting(function (Movimiento $movimiento) {
            // Uno por uno para que también se borren los archivos del disco.
            $movimiento->adjuntos()->get()->each->delete();

            $programacion = $movimiento->programacion;

            // Si era el pago del último periodo, ese vencimiento vuelve a quedar pendiente.
            if ($programacion && $movimiento->fecha_programada) {
                $programacion->deshacerPago($movimiento);
            }

            if (! $movimiento->esBase()) {
                return;
            }

            $nuevaBase = $programacion->movimientos()
                ->whereKeyNot($movimiento->id)
                ->latest('fecha')
                ->latest('id')
                ->first();

            $nuevaBase
                ? $programacion->base()->associate($nuevaBase)->save()
                : $programacion->delete();
        });
    }

    /**
     * En las rutas, la app actual usa el uuid y las versiones anteriores el id numérico.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $columna = $field ?? (ctype_digit((string) $value) ? 'id' : 'uuid');

        return static::query()->where($columna, $value)->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
            'fecha_programada' => 'date',
        ];
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(Adjunto::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function programacion(): BelongsTo
    {
        return $this->belongsTo(Programacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Es el movimiento del que la programación copia los datos.
     */
    public function esBase(): bool
    {
        return $this->programacion?->movimiento_base_id === $this->id;
    }
}
