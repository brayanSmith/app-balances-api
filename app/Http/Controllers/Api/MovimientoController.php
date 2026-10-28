<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovimientoRequest;
use App\Http\Resources\MovimientoResource;
use App\Models\Movimiento;
use App\Models\Programacion;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MovimientoController extends Controller
{
    /**
     * Movimientos con los filtros de filtrados(). Con ?page=N se pagina de a 30
     * (historial); sin él se devuelven todos (vista del mes).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtrados($request)
            ->with('programacion', 'categoria')
            ->withCount('adjuntos')
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        return MovimientoResource::collection(
            $request->has('page') ? $query->paginate(30) : $query->get()
        );
    }

    /**
     * Totales de ingresos, gastos y balance con los mismos filtros.
     */
    public function resumen(Request $request): JsonResponse
    {
        $totales = $this->filtrados($request)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto END), 0) AS ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto END), 0) AS gastos")
            ->toBase()
            ->first();

        $ingresos = (float) $totales->ingresos;
        $gastos = (float) $totales->gastos;

        // Gastos del período agrupados por categoría, de mayor a menor.
        $porCategoria = $this->filtrados($request)
            ->where('movimientos.tipo', 'gasto')
            ->leftJoin('categorias', 'categorias.id', '=', 'movimientos.categoria_id')
            ->groupBy('categorias.id', 'categorias.nombre', 'categorias.icono', 'categorias.color')
            ->orderByDesc('total')
            ->toBase()
            ->get([
                'categorias.id',
                'categorias.nombre',
                'categorias.icono',
                'categorias.color',
                DB::raw('SUM(movimientos.monto) AS total'),
            ])
            ->map(fn ($fila) => [
                'categoria_id' => $fila->id,
                'nombre' => $fila->nombre ?? 'Sin categoría',
                'icono' => $fila->icono ?? 'help-circle',
                'color' => $fila->color ?? '#94A3B8',
                'total' => (float) $fila->total,
            ]);

        return response()->json([
            'ingresos' => $ingresos,
            'gastos' => $gastos,
            'balance' => round($ingresos - $gastos, 2),
            'gastos_por_categoria' => $porCategoria,
            'balance_diario' => $this->balanceDiario($request),
            'evolucion' => $this->evolucion($request),
        ]);
    }

    /**
     * Ingresos y gastos del período agrupados en el tiempo (gráfico del historial).
     * La agrupación depende del largo: por día hasta un mes, por semana hasta tres
     * meses, por mes hasta cinco años y por año después. Los periodos sin
     * movimientos van en 0 y no se pasa de hoy. Con `agrupacion` se fuerza una, y
     * sin `desde` (período "Todo") arranca en el primer movimiento.
     *
     * @return array{agrupacion: string, puntos: list<array{desde: string, ingresos: float, gastos: float}>}|null
     */
    private function evolucion(Request $request): ?array
    {
        $request->validate(['agrupacion' => ['nullable', 'in:dia,semana,mes,anio']]);

        if (! $request->query('hasta')) {
            return null;
        }
        $desde = $request->query('desde') ?? $this->filtrados($request)->min('fecha');
        if (! $desde) {
            return null; // "Todo" sin ningún movimiento
        }

        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($request->query('hasta'))->startOfDay()->min(today());
        if ($inicio->gt($fin)) {
            return null;
        }

        $dias = $inicio->diffInDays($fin) + 1;
        $agrupacion = $request->query('agrupacion') ?? match (true) {
            $dias <= 31 => 'dia',
            $dias <= 92 => 'semana',
            $dias <= 366 * 5 => 'mes',
            default => 'anio',
        };

        // Inicio del grupo al que pertenece cada fecha. Las semanas se cuentan de
        // 7 en 7 desde el primer día del período; el primer mes o año puede ser parcial.
        $grupoDe = fn (Carbon $fecha): string => match ($agrupacion) {
            'dia' => $fecha->toDateString(),
            'semana' => $inicio->copy()->addDays(intdiv((int) $inicio->diffInDays($fecha), 7) * 7)->toDateString(),
            'mes' => $fecha->copy()->startOfMonth()->max($inicio)->toDateString(),
            'anio' => $fecha->copy()->startOfYear()->max($inicio)->toDateString(),
        };

        $puntos = [];
        $paso = ['dia' => '1 day', 'semana' => '7 days', 'mes' => '1 month', 'anio' => '1 year'][$agrupacion];
        $cursor = $inicio->copy();
        while ($cursor->lte($fin)) {
            $puntos[$grupoDe($cursor)] = ['desde' => $grupoDe($cursor), 'ingresos' => 0.0, 'gastos' => 0.0];
            $cursor = in_array($agrupacion, ['mes', 'anio'])
                ? $cursor->startOf($agrupacion === 'mes' ? 'month' : 'year')->add($paso)
                : $cursor->add($paso);
        }

        $porDia = $this->filtrados($request)
            ->whereDate('fecha', '<=', $fin)
            ->selectRaw('fecha')
            ->selectRaw("SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) AS ingresos")
            ->selectRaw("SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END) AS gastos")
            ->groupBy('fecha')
            ->toBase()
            ->get();

        foreach ($porDia as $fila) {
            $grupo = $grupoDe(Carbon::parse(substr($fila->fecha, 0, 10)));
            $puntos[$grupo]['ingresos'] = round($puntos[$grupo]['ingresos'] + (float) $fila->ingresos, 2);
            $puntos[$grupo]['gastos'] = round($puntos[$grupo]['gastos'] + (float) $fila->gastos, 2);
        }

        return ['agrupacion' => $agrupacion, 'puntos' => array_values($puntos)];
    }

    /**
     * Balance acumulado día a día dentro del período (para el minigráfico).
     * Arranca en 0 el primer día y llega hasta hoy si el período sigue en curso.
     *
     * @return list<array{fecha: string, balance: float}>
     */
    private function balanceDiario(Request $request): array
    {
        if ($mes = $request->query('mes')) {
            $inicio = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->startOfDay();
            $fin = $inicio->copy()->endOfMonth()->startOfDay();
        } elseif ($request->query('desde') && $request->query('hasta')) {
            $inicio = Carbon::parse($request->query('desde'));
            $fin = Carbon::parse($request->query('hasta'));
        } else {
            return [];
        }

        $fin = $fin->min(today());
        if ($inicio->gt($fin) || $inicio->diffInDays($fin) > 400) {
            return [];
        }

        $netoPorDia = $this->filtrados($request)
            ->selectRaw("fecha, SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END) AS neto")
            ->groupBy('fecha')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($fila) => [substr($fila->fecha, 0, 10) => (float) $fila->neto]);

        $acumulado = 0.0;
        $serie = [];
        foreach (CarbonPeriod::create($inicio, $fin) as $dia) {
            $acumulado += $netoPorDia[$dia->toDateString()] ?? 0;
            $serie[] = ['fecha' => $dia->toDateString(), 'balance' => round($acumulado, 2)];
        }

        return $serie;
    }

    public function store(MovimientoRequest $request): MovimientoResource
    {
        // Reenvío de algo creado sin conexión que ya llegó: se devuelve el existente.
        if ($existente = self::yaRecibido($request)) {
            return new MovimientoResource($existente->load('programacion', 'categoria', 'adjuntos'));
        }

        $movimiento = DB::transaction(function () use ($request) {
            $movimiento = $request->user()->movimientos()->make($request->datosMovimiento());
            $movimiento->cliente_id = $request->input('cliente_id');
            $movimiento->save();

            if ($request->boolean('programado')) {
                Programacion::crearDesde($movimiento, $request->reglaProgramacion());
            }

            return $movimiento;
        });

        return new MovimientoResource($movimiento->load('programacion', 'categoria', 'adjuntos'));
    }

    // En show/update/destroy, el scope de BelongsToTenant hace que un
    // movimiento de otro tenant no se encuentre (404).
    public function show(Movimiento $movimiento): MovimientoResource
    {
        return new MovimientoResource($movimiento->load('programacion', 'categoria', 'adjuntos'));
    }

    public function update(MovimientoRequest $request, Movimiento $movimiento): MovimientoResource
    {
        DB::transaction(function () use ($request, $movimiento) {
            $movimiento->update($request->datosMovimiento());
            $programacion = $movimiento->programacion;

            if (! $programacion) {
                if ($request->boolean('programado')) {
                    Programacion::crearDesde($movimiento, $request->reglaProgramacion());
                }

                return;
            }

            // Apagar "programado" detiene la repetición; los movimientos se conservan.
            if ($request->has('programado') && ! $request->boolean('programado')) {
                $programacion->delete();

                return;
            }

            // "Este y los siguientes": los próximos se copian de este movimiento.
            if ($request->boolean('actualizar_programado')) {
                $programacion->base()->associate($movimiento);
            }

            if ($request->boolean('programado')) {
                $programacion->fill($request->reglaProgramacion());
            }

            $cambioLaRegla = $programacion->isDirty(['intervalo', 'frecuencia']);
            $cambioElUltimoPago = $movimiento->wasChanged('fecha')
                && $programacion->ultimoMovimiento()?->is($movimiento);

            if ($cambioLaRegla || $cambioElUltimoPago) {
                $programacion->reprogramar();
            } else {
                $programacion->actualizarVigencia();
            }

            $programacion->save();
        });

        $movimiento->unsetRelation('programacion');

        return new MovimientoResource($movimiento->load('programacion', 'categoria', 'adjuntos'));
    }

    public function destroy(Movimiento $movimiento): Response
    {
        DB::transaction(fn () => $movimiento->delete());

        return response()->noContent();
    }

    /**
     * Movimiento ya creado con el mismo cliente_id (el scope limita al tenant).
     */
    public static function yaRecibido(Request $request): ?Movimiento
    {
        $clienteId = $request->input('cliente_id');

        return $clienteId ? Movimiento::query()->where('cliente_id', $clienteId)->first() : null;
    }

    /**
     * Filtros: ?mes=2026-10 | ?desde=2026-10-01&hasta=2026-10-26, más
     * tipo (ingreso/gasto), categoria_id y q (busca en la descripción).
     */
    private function filtrados(Request $request): Builder
    {
        $filtros = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tipo' => ['nullable', 'in:ingreso,gasto'],
            'categoria_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ], [
            'hasta.after_or_equal' => 'La fecha "hasta" debe ser igual o posterior a "desde".',
        ]);

        return Movimiento::query()
            ->when($filtros['mes'] ?? null, function (Builder $query, string $mes) {
                [$anio, $numMes] = explode('-', $mes);
                $query->whereYear('fecha', $anio)->whereMonth('fecha', $numMes);
            })
            ->when($filtros['desde'] ?? null, fn (Builder $query, string $desde) => $query->whereDate('fecha', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn (Builder $query, string $hasta) => $query->whereDate('fecha', '<=', $hasta))
            ->when($filtros['tipo'] ?? null, fn (Builder $query, string $tipo) => $query->where('movimientos.tipo', $tipo))
            ->when($filtros['categoria_id'] ?? null, fn (Builder $query, $id) => $query->where('movimientos.categoria_id', $id))
            ->when($filtros['q'] ?? null, fn (Builder $query, string $q) => $query->where('descripcion', 'like', '%'.addcslashes($q, '%_\\').'%'));
    }
}
