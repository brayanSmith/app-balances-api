<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios de la app y dónde guarda cada uno sus datos. Las cuentas se crean y se
 * eliminan desde la app (o /eliminar-cuenta), no desde aquí.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'almacenamiento' => ['nullable', 'in:nube,local'],
        ]);

        $usuarios = User::query()
            // Con cuántos movimientos ocupa el servidor (sin el scope de tenant del administrador).
            ->withCount(['movimientos' => fn (Builder $query) => $query->withoutGlobalScopes()])
            ->when($filtros['q'] ?? null, fn (Builder $query, string $q) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')
                    ->orWhere('email', 'like', '%'.addcslashes($q, '%_\\').'%'),
            ))
            ->when($filtros['almacenamiento'] ?? null, fn (Builder $query, string $modo) => $query->where('almacenamiento', $modo))
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'almacenamiento' => $user->almacenamiento,
                'es_super_admin' => $user->esSuperAdmin(),
                'movimientos' => $user->movimientos_count,
                'registro' => $user->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/usuarios', [
            'usuarios' => $usuarios,
            'filtros' => [
                'q' => $filtros['q'] ?? '',
                'almacenamiento' => $filtros['almacenamiento'] ?? null,
            ],
        ]);
    }

    /**
     * La app aplica el cambio la próxima vez que se conecta: al pasar a "solo en el
     * teléfono" baja todo y libera el servidor; al volver a la nube, sube todo.
     */
    public function almacenamiento(Request $request, User $user): RedirectResponse
    {
        $datos = $request->validate(['almacenamiento' => ['required', 'in:nube,local']]);

        // No está en $fillable a propósito: la API (registro, perfil) nunca debe poder cambiarlo.
        $user->forceFill(['almacenamiento' => $datos['almacenamiento']])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $datos['almacenamiento'] === 'local'
                ? "{$user->name} guardará sus datos solo en el teléfono."
                : "{$user->name} volverá a guardar sus datos en la nube.",
        ]);

        return back();
    }
}
