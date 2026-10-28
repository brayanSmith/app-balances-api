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
