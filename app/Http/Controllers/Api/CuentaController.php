<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adjunto;
use App\Models\Categoria;
use App\Models\Importacion;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Services\EliminadorDeCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CuentaController extends Controller
{
    /**
     * Deja la cuenta "de fábrica": borra movimientos, comprobantes, programados,
     * importaciones y categorías del tenant, y vuelve a crear las predeterminadas.
     * Los usuarios y la sesión se conservan. Pide la contraseña y escribir BORRAR.
     */
    public function restablecer(Request $request): JsonResponse
    {
        $request->user()->exigirPassword('password', 'borrar tus datos');

        $request->validate([
            'confirmacion' => ['required', 'in:BORRAR'],
            'password' => ['required', 'current_password:sanctum'],
        ], [
            'confirmacion.in' => 'Escribe BORRAR (en mayúsculas) para confirmar.',
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $tenant = $request->user()->tenant;

        $borrados = DB::transaction(function () use ($tenant) {
            // Sin el scope global para que quede explícito qué tenant se borra.
            $delTenant = fn (string $modelo) => $modelo::withoutGlobalScopes()->where('tenant_id', $tenant->id);

            // Orden: primero lo que depende de otros registros.
            $borrados = [
                'adjuntos' => $delTenant(Adjunto::class)->delete(),
                'movimientos' => $delTenant(Movimiento::class)->delete(),
                'programaciones' => $delTenant(Programacion::class)->delete(),
                'importaciones' => $delTenant(Importacion::class)->delete(),
                'categorias' => $delTenant(Categoria::class)->delete(),
            ];

            $tenant->categorias()->createMany(Categoria::PREDETERMINADAS);

            return $borrados;
        });

        // Los archivos, una vez confirmado el borrado en la base de datos.
        Storage::disk()->deleteDirectory("adjuntos/{$tenant->id}");
        Storage::disk()->deleteDirectory("tmp/importaciones/{$tenant->id}");

        return response()->json([
            'message' => 'Listo: tu cuenta quedó como nueva.',
            'borrados' => $borrados,
        ]);
    }

    /**
     * Elimina la cuenta y todos sus datos, sin vuelta atrás. Pide escribir ELIMINAR
     * y, si la cuenta tiene contraseña, la contraseña (quien entró solo con Google no tiene).
     */
    public function eliminar(Request $request, EliminadorDeCuenta $eliminador): Response
    {
        $user = $request->user();

        $request->validate([
            'confirmacion' => ['required', 'in:ELIMINAR'],
            'password' => $user->password !== null ? ['required', 'current_password:sanctum'] : ['nullable'],
        ], [
            'confirmacion.in' => 'Escribe ELIMINAR (en mayúsculas) para confirmar.',
            'password.required' => 'Escribe tu contraseña para confirmar.',
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $eliminador->eliminar($user);

        return response()->noContent();
    }
}
