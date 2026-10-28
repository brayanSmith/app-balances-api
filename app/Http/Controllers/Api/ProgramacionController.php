<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MovimientoResource;
use App\Http\Resources\PendienteResource;
use App\Models\Programacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Los programados no se registran solos: aparecen como pendientes y el usuario
 * confirma cada pago con el monto real (o lo omite). Así el balance siempre
 * refleja el dinero que de verdad se movió.
 */
class ProgramacionController extends Controller
{
    /**
     * Vencidos (sin importar la antigüedad), los que vencen en los próximos ?dias=7
     * y, con ?mes=2026-11, todos los que vencen hasta el fin de ese mes (para poder
     * pagar por adelantado).
     */
    public function pendientes(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'dias' => ['nullable', 'integer', 'min:0', 'max:60'],
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);

        $hasta = today()->addDays($request->integer('dias', 7));
        if ($mes = $request->query('mes')) {
            $hasta = $hasta->max(Carbon::createFromFormat('Y-m-d', "{$mes}-01")->endOfMonth()->startOfDay());
        }

        $pendientes = Programacion::query()
            ->with('base.categoria')
            ->where('activo', true)
            ->whereNotNull('movimiento_base_id')
            ->whereDate('proxima_fecha', '<=', $hasta)
            ->orderBy('proxima_fecha')
            ->get();

        return PendienteResource::collection($pendientes);
    }

    /**
     * Registra el pago del vencimiento pendiente. Los comprobantes se suben
     * después al movimiento creado (POST /movimientos/{id}/adjuntos).
     */
    public function pagar(Request $request, Programacion $programacion): MovimientoResource
    {
        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'actualizar_base' => ['sometimes', 'boolean'],
            'cliente_id' => ['nullable', 'uuid'],
        ]);

        // Pago enviado sin conexión que ya llegó: no se registra ni avanza dos veces.
        if ($existente = MovimientoController::yaRecibido($request)) {
            return new MovimientoResource($existente->load('programacion', 'categoria', 'adjuntos'));
        }

        $this->asegurarActiva($programacion);

        $movimiento = $programacion->registrarPago($request->user(), [
            ...$datos,
            'actualizar_base' => $request->boolean('actualizar_base'),
        ]);

        return new MovimientoResource($movimiento->load('programacion', 'categoria', 'adjuntos'));
    }

    /**
     * Salta el vencimiento pendiente sin registrar nada.
     */
    public function omitir(Programacion $programacion): Response
    {
        $this->asegurarActiva($programacion);
        $programacion->omitir();

        return response()->noContent();
    }

    private function asegurarActiva(Programacion $programacion): void
    {
        if (! $programacion->activo || ! $programacion->base) {
            throw ValidationException::withMessages(['programacion' => ['Este programado ya terminó.']]);
        }
    }
}
