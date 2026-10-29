<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Adjunto;
use App\Models\Movimiento;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio del panel: cuántas cuentas guardan en la nube o solo en el teléfono y cuánto
 * ocupan en el servidor. Sin el scope de tenant: el del administrador filtraría todo
 * lo de los demás.
 */
class InicioController extends Controller
{
    public function __invoke(): Response
    {
        $porModo = User::query()->selectRaw('almacenamiento, COUNT(*) AS total')->groupBy('almacenamiento')->pluck('total', 'almacenamiento');

        return Inertia::render('admin/inicio', [
            'estadisticas' => [
                'cuentas_nube' => (int) ($porModo['nube'] ?? 0),
                'cuentas_local' => (int) ($porModo['local'] ?? 0),
                'movimientos' => Movimiento::withoutGlobalScopes()->count(),
                'comprobantes' => Adjunto::withoutGlobalScopes()->count(),
                'comprobantes_bytes' => (int) Adjunto::withoutGlobalScopes()->sum('tamano'),
            ],
            'recientes' => User::query()
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'email', 'almacenamiento', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'almacenamiento' => $user->almacenamiento,
                    'registro' => $user->created_at?->toDateString(),
                ]),
        ]);
    }
}
