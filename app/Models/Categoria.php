<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tipo', 'nombre', 'icono', 'color'])]
class Categoria extends Model
{
    use BelongsToTenant;

    protected $table = 'categorias';

    /**
     * Categorías con las que arranca cada tenant (íconos de Ionicons).
     */
    public const PREDETERMINADAS = [
        ['tipo' => 'gasto', 'nombre' => 'Comida', 'icono' => 'fast-food', 'color' => '#F97316'],
        ['tipo' => 'gasto', 'nombre' => 'Transporte', 'icono' => 'bus', 'color' => '#3B82F6'],
        ['tipo' => 'gasto', 'nombre' => 'Vivienda', 'icono' => 'home', 'color' => '#8B5CF6'],
        ['tipo' => 'gasto', 'nombre' => 'Servicios', 'icono' => 'flash', 'color' => '#EAB308'],
        ['tipo' => 'gasto', 'nombre' => 'Salud', 'icono' => 'medkit', 'color' => '#EF4444'],
        ['tipo' => 'gasto', 'nombre' => 'Compras', 'icono' => 'cart', 'color' => '#14B8A6'],
        ['tipo' => 'gasto', 'nombre' => 'Ocio', 'icono' => 'game-controller', 'color' => '#EC4899'],
        ['tipo' => 'gasto', 'nombre' => 'Educación', 'icono' => 'school', 'color' => '#6366F1'],
        ['tipo' => 'gasto', 'nombre' => 'Otros', 'icono' => 'pricetag', 'color' => '#64748B'],
        ['tipo' => 'ingreso', 'nombre' => 'Salario', 'icono' => 'briefcase', 'color' => '#10B981'],
        ['tipo' => 'ingreso', 'nombre' => 'Ventas', 'icono' => 'storefront', 'color' => '#22C55E'],
        ['tipo' => 'ingreso', 'nombre' => 'Freelance', 'icono' => 'laptop', 'color' => '#06B6D4'],
        ['tipo' => 'ingreso', 'nombre' => 'Inversiones', 'icono' => 'trending-up', 'color' => '#0EA5E9'],
        ['tipo' => 'ingreso', 'nombre' => 'Otros', 'icono' => 'cash', 'color' => '#64748B'],
    ];

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }
}
