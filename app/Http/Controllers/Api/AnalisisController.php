<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Programacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pantalla "Análisis": el saldo acumulado agrupado por semana, quincena, mes o año
 * (para el gráfico de área y el de velas) y, si se pide, su proyección hasta un mes futuro.
 */
class AnalisisController extends Controller
{
    /** Hasta cuántos meses hacia adelante se puede proyectar. */
    public const MESES_PROYECCION = 24;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'agrupacion' => ['required', 'in:semana,quincena,mes,anio'],
            'proyectar_hasta' => [
                'nullable',
                'date_format:Y-m',
                'after_or_equal:'.today()->format('Y-m'),
                'before_or_equal:'.today()->addMonthsNoOverflow(self::MESES_PROYECCION)->format('Y-m'),
            ],
        ], [
            'hasta.after_or_equal' => 'La fecha "hasta" debe ser igual o posterior a "desde".',
            'proyectar_hasta.after_or_equal' => 'Solo se puede proyectar desde el mes actual.',
            'proyectar_hasta.before_or_equal' => 'Se puede proyectar hasta '.self::MESES_PROYECCION.' meses adelante.',
        ]);

        $agrupacion = $request->input('agrupacion');
        $inicio = Carbon::parse($request->input('desde'))->startOfDay();
        // Lo registrado llega como mucho hasta hoy: lo que sigue es proyección.
        $fin = Carbon::parse($request->input('hasta'))->startOfDay()->min(today());

        $saldoInicial = $this->saldoAntesDe($inicio);
        $porDia = $this->movimientosPorDia($inicio, $fin);
        $real = $this->agrupar($porDia, $inicio, $fin, $agrupacion, $saldoInicial, false);
        $saldoActual = $real ? end($real)['cierre'] : $saldoInicial;

        $proyeccion = null;
        $periodosProyectados = [];
        // Solo se proyecta desde hoy: un período que terminó antes dejaría un hueco.
        if (($mes = $request->input('proyectar_hasta')) && $fin->isToday()) {
            $manana = today()->addDay();
            $finProyeccion = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->endOfMonth()->startOfDay();
            [$diario, $proyeccion] = $this->proyectar($inicio, $fin, $manana, $finProyeccion);
            $periodosProyectados = $this->agrupar($diario, $manana, $finProyeccion, $agrupacion, $saldoActual, true);
            $proyeccion['saldo_final'] = $periodosProyectados ? end($periodosProyectados)['cierre'] : $saldoActual;
        }

        return response()->json([
            'agrupacion' => $agrupacion,
            'saldo_inicial' => $saldoInicial,
            'saldo_actual' => $saldoActual,
            'ingresos' => round(array_sum(array_column($porDia, 'ingresos')), 2),
            'gastos' => round(array_sum(array_column($porDia, 'gastos')), 2),
            'periodos' => [...$real, ...$periodosProyectados],
            'proyeccion' => $proyeccion,
        ]);
    }

    /** Saldo con el que se llega al período: todo lo registrado antes. */
    private function saldoAntesDe(Carbon $fecha): float
    {
        $fila = Movimiento::query()
            ->whereDate('fecha', '<', $fecha)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) AS saldo")
            ->toBase()
            ->first();

        return round((float) $fila->saldo, 2);
    }

    /**
     * @return array<string, array{ingresos: float, gastos: float}> por fecha (Y-m-d)
     */
    private function movimientosPorDia(Carbon $inicio, Carbon $fin, bool $soloNoProgramados = false): array
    {
        if ($inicio->gt($fin)) {
            return [];
        }

        return Movimiento::query()
            ->whereDate('fecha', '>=', $inicio)
            ->whereDate('fecha', '<=', $fin)
            ->when($soloNoProgramados, fn ($q) => $q->whereNull('programacion_id'))
            ->selectRaw('fecha')
            ->selectRaw("SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) AS ingresos")
            ->selectRaw("SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END) AS gastos")
            ->groupBy('fecha')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($fila) => [substr($fila->fecha, 0, 10) => [
                'ingresos' => (float) $fila->ingresos,
                'gastos' => (float) $fila->gastos,
            ]])
            ->all();
    }

    /**
     * Ingresos y gastos estimados día a día desde mañana: los vencimientos de los
     * programados (fecha y monto conocidos; los vencidos, mañana) más el promedio
     * diario de lo no programado en el período filtrado.
     *
     * @return array{0: array<string, array{ingresos: float, gastos: float}>, 1: array<string, mixed>}
     */
    private function proyectar(Carbon $inicio, Carbon $fin, Carbon $manana, Carbon $finProyeccion): array
    {
        $diasBase = (int) $inicio->diffInDays($fin) + 1;
        $base = $this->movimientosPorDia($inicio, $fin, soloNoProgramados: true);
        $promedio = [
            'ingresos' => round(array_sum(array_column($base, 'ingresos')) / $diasBase, 2),
            'gastos' => round(array_sum(array_column($base, 'gastos')) / $diasBase, 2),
        ];

        $diario = [];
        for ($dia = $manana->copy(); $dia->lte($finProyeccion); $dia->addDay()) {
            $diario[$dia->toDateString()] = $promedio;
        }

        $programados = ['ingresos' => 0.0, 'gastos' => 0.0, 'vencimientos' => 0];
        $programaciones = Programacion::query()
            ->with('base')
            ->where('activo', true)
            ->whereNotNull('movimiento_base_id')
            ->get();

        foreach ($programaciones as $programacion) {
            $campo = $programacion->base->tipo === 'ingreso' ? 'ingresos' : 'gastos';
            $monto = (float) $programacion->base->monto;

            for ($n = $programacion->ocurrencias; ; $n++) {
                $fecha = $programacion->fechaDeOcurrencia($n);
                if ($fecha->gt($finProyeccion) || ($programacion->fecha_fin && $fecha->gt($programacion->fecha_fin))) {
                    break;
                }
                $dia = $fecha->max($manana)->toDateString();
                $diario[$dia][$campo] = round($diario[$dia][$campo] + $monto, 2);
                $programados[$campo] = round($programados[$campo] + $monto, 2);
                $programados['vencimientos']++;
            }
        }

        return [$diario, [
            'hasta' => $finProyeccion->toDateString(),
            'ingresos' => round(array_sum(array_column($diario, 'ingresos')), 2),
            'gastos' => round(array_sum(array_column($diario, 'gastos')), 2),
            'programados' => $programados,
            'promedio_diario' => $promedio,
            'dias_base' => $diasBase,
        ]];
    }

    /**
     * Recorre los días de $inicio a $fin acumulando el saldo y arma un período por
     * semana (lunes a domingo), quincena (1–15 y 16–fin), mes o año. El primero y el
     * último pueden ser parciales. Cada uno trae sus velas: saldo de apertura,
     * cierre, máximo y mínimo.
     *
     * @param  array<string, array{ingresos: float, gastos: float}>  $porDia
     * @return list<array<string, mixed>>
     */
    private function agrupar(array $porDia, Carbon $inicio, Carbon $fin, string $agrupacion, float $saldo, bool $proyectado): array
    {
        $periodos = [];
        $actual = null;

        for ($dia = $inicio->copy(); $dia->lte($fin); $dia->addDay()) {
            $clave = $this->inicioDePeriodo($dia, $agrupacion)->max($inicio)->toDateString();

            if ($actual === null || $actual['desde'] !== $clave) {
                if ($actual !== null) {
                    $periodos[] = $actual;
                }
                $actual = [
                    'desde' => $clave,
                    'hasta' => $clave,
                    'ingresos' => 0.0,
                    'gastos' => 0.0,
                    'apertura' => $saldo,
                    'cierre' => $saldo,
                    'maximo' => $saldo,
                    'minimo' => $saldo,
                    'proyectado' => $proyectado,
                ];
            }

            $movs = $porDia[$dia->toDateString()] ?? null;
            if ($movs) {
                $saldo = round($saldo + $movs['ingresos'] - $movs['gastos'], 2);
                $actual['ingresos'] = round($actual['ingresos'] + $movs['ingresos'], 2);
                $actual['gastos'] = round($actual['gastos'] + $movs['gastos'], 2);
                $actual['maximo'] = max($actual['maximo'], $saldo);
                $actual['minimo'] = min($actual['minimo'], $saldo);
            }
            $actual['hasta'] = $dia->toDateString();
            $actual['cierre'] = $saldo;
        }

        if ($actual !== null) {
            $periodos[] = $actual;
        }

        return $periodos;
    }

    private function inicioDePeriodo(Carbon $dia, string $agrupacion): Carbon
    {
        return match ($agrupacion) {
            'semana' => $dia->copy()->startOfWeek(Carbon::MONDAY),
            'quincena' => $dia->day <= 15 ? $dia->copy()->startOfMonth() : $dia->copy()->setDay(16),
            'mes' => $dia->copy()->startOfMonth(),
            'anio' => $dia->copy()->startOfYear(),
        };
    }
}
