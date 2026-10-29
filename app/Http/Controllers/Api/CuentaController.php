<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Services\EliminadorDeCuenta;
use App\Services\VaciadorDeDatos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CuentaController extends Controller
{
    /**
     * Deja la cuenta "de fábrica": borra movimientos, comprobantes, programados,
     * importaciones y categorías del tenant, y vuelve a crear las predeterminadas.
     * Los usuarios y la sesión se conservan. Pide la contraseña y escribir BORRAR.
     * Si la cuenta guarda solo en el teléfono, aquí solo se confirma la contraseña:
     * la app borra sus datos.
     */
    public function restablecer(Request $request, VaciadorDeDatos $vaciador): JsonResponse
    {
        $request->user()->exigirPassword('password', 'borrar tus datos');

        $request->validate([
            'confirmacion' => ['required', 'in:BORRAR'],
            'password' => ['required', 'current_password:sanctum'],
        ], [
            'confirmacion.in' => 'Escribe BORRAR (en mayúsculas) para confirmar.',
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();
        $tenant = $user->tenant;

        $borrados = $user->almacenamiento === 'local'
            ? []
            : $vaciador->vaciar($tenant, avisarBorrados: true, despues: fn () => $tenant->categorias()->createMany(Categoria::PREDETERMINADAS));

        return response()->json([
            'message' => 'Listo: tu cuenta quedó como nueva.',
            'borrados' => $borrados,
        ]);
    }

    /**
     * La cuenta pasó a guardar solo en el teléfono y la app ya bajó todo (también los
     * comprobantes): se borran sus datos del servidor para liberar espacio.
     */
    public function liberarNube(Request $request, VaciadorDeDatos $vaciador): Response
    {
        if ($request->user()->almacenamiento !== 'local') {
            throw ValidationException::withMessages([
                'almacenamiento' => ['Esta cuenta guarda sus datos en la nube: no se pueden borrar del servidor.'],
            ]);
        }

        $vaciador->vaciar($request->user()->tenant, avisarBorrados: false);

        return response()->noContent();
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
