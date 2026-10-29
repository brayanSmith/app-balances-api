<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sincronizador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints de synchronize() de WatermelonDB (ver App\Services\Sincronizador).
 */
class SincronizacionController extends Controller
{
    public function pull(Request $request, Sincronizador $sincronizador): JsonResponse
    {
        $request->validate(['last_pulled_at' => ['nullable', 'integer', 'min:0']]);

        return response()->json($sincronizador->pull($request->integer('last_pulled_at') ?: null));
    }

    /**
     * Las cuentas "solo en el teléfono" pueden bajar (lo que quedaba al cambiar de modo),
     * pero no subir: así el servidor no vuelve a llenarse con sus datos.
     */
    public function push(Request $request, Sincronizador $sincronizador): Response
    {
        abort_if(
            $request->user()->almacenamiento === 'local',
            403,
            'Esta cuenta guarda sus datos solo en el teléfono.',
        );

        $request->validate([
            'changes' => ['present', 'array'],
            'last_pulled_at' => ['nullable', 'integer', 'min:0'],
        ]);

        $sincronizador->push($request->user(), $request->input('changes'), $request->integer('last_pulled_at') ?: null);

        return response()->noContent();
    }
}
