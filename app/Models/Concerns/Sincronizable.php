<?php

namespace App\Models\Concerns;

use App\Models\Eliminado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Registros que la app guarda en su SQLite y sincroniza con /api/sync:
 * tienen un uuid (el id que usa la app) y al borrarse dejan un rastro en eliminados.
 */
trait Sincronizable
{
    protected static function bootSincronizable(): void
    {
        static::creating(function (Model $model) {
            $model->uuid ??= $model->getAttribute('cliente_id') ?? (string) Str::uuid();
        });

        static::deleted(fn (Model $model) => $model->registrarEliminado());
    }

    public function registrarEliminado(): void
    {
        Eliminado::create(['tenant_id' => $this->tenant_id, 'tabla' => $this->getTable(), 'uuid' => $this->uuid]);
    }

    /**
     * Para borrados masivos (sin eventos): deja el rastro de lo que $query va a borrar.
     */
    public static function registrarEliminados(Builder $query): void
    {
        $tabla = $query->getModel()->getTable();

        $query->clone()->select(['id', 'tenant_id', 'uuid'])->chunkById(500, function ($modelos) use ($tabla) {
            Eliminado::insert($modelos->map(fn (Model $model) => [
                'tenant_id' => $model->tenant_id,
                'tabla' => $tabla,
                'uuid' => $model->uuid,
                'created_at' => now(),
            ])->all());
        });
    }
}
