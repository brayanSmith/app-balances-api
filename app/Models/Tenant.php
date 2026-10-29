<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            $tenant->categorias()->createMany(Categoria::PREDETERMINADAS);
        });
    }

    /**
     * Abre una cuenta nueva (registro o primer ingreso con Google): su tenant y su
     * usuario, con el almacenamiento inicial de config/balances.php. Si es "solo en el
     * teléfono", el servidor no guarda las categorías predeterminadas: las crea la app.
     *
     * @param  callable(Tenant): User  $crearUsuario
     */
    public static function abrirCuenta(string $nombre, callable $crearUsuario): User
    {
        $almacenamiento = config('balances.almacenamiento_inicial') === 'nube' ? 'nube' : 'local';

        // Sin eventos no se dispara el created que crea las categorías.
        $tenant = $almacenamiento === 'local'
            ? static::withoutEvents(fn () => static::create(['nombre' => $nombre]))
            : static::create(['nombre' => $nombre]);

        $user = $crearUsuario($tenant);
        // No está en $fillable a propósito: la API nunca debe poder elegirlo.
        $user->forceFill(['almacenamiento' => $almacenamiento])->save();

        return $user;
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }
}
