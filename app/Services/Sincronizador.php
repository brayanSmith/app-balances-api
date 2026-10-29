<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Eliminado;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sincronización con la base SQLite de la app (protocolo de WatermelonDB).
 *
 * - pull: lo que cambió en el servidor desde la última vez que el dispositivo bajó datos.
 * - push: lo que cambió en el dispositivo. Se guarda tal cual llega: la lógica de
 *   negocio (programados, pagos...) ya la aplicó la app, así que aquí no se repite.
 *
 * La app identifica cada registro por su uuid; las relaciones viajan también como uuid.
 */
class Sincronizador
{
    /**
     * En este orden se guardan (y al revés se borran).
     */
    private const TABLAS = [
        'categorias' => [
            'modelo' => Categoria::class,
            'columnas' => ['tipo', 'nombre', 'icono', 'color'],
            'relaciones' => [],
        ],
        'programaciones' => [
            'modelo' => Programacion::class,
            'columnas' => ['intervalo', 'frecuencia', 'fecha_inicio', 'fecha_fin', 'ocurrencias', 'proxima_fecha', 'activo'],
            'relaciones' => ['movimiento_base_id' => 'movimientos'],
        ],
        'movimientos' => [
            'modelo' => Movimiento::class,
            'columnas' => ['tipo', 'monto', 'descripcion', 'fecha', 'fecha_programada'],
            'relaciones' => ['categoria_id' => 'categorias', 'programacion_id' => 'programaciones'],
            // Solo se envían a la app (los comprobantes se suben aparte).
            'conteos' => ['adjuntos'],
        ],
    ];

    /**
     * uuid → id de los registros ya buscados, por tabla.
     *
     * @var array<string, array<string, int>>
     */
    private array $ids = [];

    /**
     * @return array{changes: array<string, array{created: list<array>, updated: list<array>, deleted: list<string>}>, timestamp: int}
     */
    public function pull(?int $ultimoPull): array
    {
        // Se marca antes de consultar y se compara con >=: lo que cambie mientras
        // tanto (o en el mismo segundo) se vuelve a enviar la próxima vez, no se pierde.
        $ahora = now()->startOfSecond();
        $desde = $this->fecha($ultimoPull);
        $cambios = [];

        foreach (self::TABLAS as $tabla => $config) {
            $registros = $config['modelo']::query()
                ->withCount($config['conteos'] ?? [])
                ->when($desde, fn ($query) => $query->where('updated_at', '>=', $desde))
                ->get();

            $uuids = $this->uuidsRelacionados($registros, $config['relaciones']);
            [$creados, $actualizados] = $registros->partition(
                fn (Model $registro) => ! $desde || $registro->created_at->gte($desde)
            );

            $cambios[$tabla] = [
                'created' => $creados->map(fn (Model $registro) => $this->paraApp($registro, $config, $uuids))->values()->all(),
                'updated' => $actualizados->map(fn (Model $registro) => $this->paraApp($registro, $config, $uuids))->values()->all(),
                // En la primera sincronización el dispositivo no tiene nada que borrar.
                'deleted' => $desde
                    ? Eliminado::query()->where('tabla', $tabla)->where('created_at', '>=', $desde)->pluck('uuid')->unique()->values()->all()
                    : [],
            ];
        }

        return ['changes' => $cambios, 'timestamp' => $ahora->getTimestampMs()];
    }

    /**
     * @param  array<string, array{created?: list<array>, updated?: list<array>, deleted?: list<string>}>  $cambios
     */
    public function push(User $user, array $cambios, ?int $ultimoPull): void
    {
        $this->validar($cambios);
        $desde = $this->fecha($ultimoPull);

        try {
            DB::transaction(function () use ($user, $cambios, $desde) {
                $guardados = [];

                // Primero los datos y luego las relaciones: programaciones y movimientos
                // se apuntan entre sí y pueden llegar nuevos los dos en el mismo envío.
                foreach (self::TABLAS as $tabla => $config) {
                    $guardados[$tabla] = $this->guardar($user, $tabla, $config, $cambios[$tabla] ?? [], $desde);
                }

                foreach (self::TABLAS as $tabla => $config) {
                    foreach ($guardados[$tabla] as [$modelo, $registro]) {
                        foreach ($config['relaciones'] as $columna => $destino) {
                            $uuid = $registro[$columna] ?? null;
                            $modelo->{$columna} = $uuid ? $this->idDe($destino, $uuid) : null;
                        }
                        $modelo->saveQuietly();
                    }
                }

                foreach (array_reverse(self::TABLAS, true) as $tabla => $config) {
                    $this->borrar($config['modelo'], $cambios[$tabla]['deleted'] ?? []);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['categorias' => 'Ya existe una categoría con ese nombre.']);
        }
    }

    /**
     * @return list<array{0: Model, 1: array}>
     */
    private function guardar(User $user, string $tabla, array $config, array $cambios, ?Carbon $desde): array
    {
        $registros = [
            ...array_map(fn (array $registro) => [$registro, false], $cambios['created'] ?? []),
            ...array_map(fn (array $registro) => [$registro, true], $cambios['updated'] ?? []),
        ];
        $uuids = array_map(fn (array $par) => $par[0]['id'], $registros);

        $existentes = $config['modelo']::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $eliminados = Eliminado::query()->where('tabla', $tabla)->whereIn('uuid', $uuids)->pluck('uuid')->flip();
        $guardados = [];

        foreach ($registros as [$registro, $esActualizacion]) {
            $uuid = $registro['id'];

            // Lo borrado no revive. Si el dispositivo lo editó, que primero baje el borrado.
            if ($eliminados->has($uuid)) {
                $esActualizacion && $this->conflicto();

                continue;
            }

            $modelo = $existentes->get($uuid);

            // Otro dispositivo lo cambió después del último pull de este: que baje eso primero.
            if ($modelo && $esActualizacion && $desde && $modelo->updated_at->gt($desde)) {
                $this->conflicto();
            }

            // Un "created" que ya existe es un reenvío (se perdió la respuesta): se actualiza.
            if (! $modelo) {
                $modelo = new $config['modelo'];
                $modelo->uuid = $uuid;
                $modelo->tenant_id = $user->tenant_id;
                if ($modelo instanceof Movimiento) {
                    $modelo->user_id = $user->id;
                }
            }

            $modelo->forceFill(array_intersect_key($registro, array_flip($config['columnas'])));
            $modelo->saveQuietly();

            $this->ids[$tabla][$uuid] = $modelo->id;
            $guardados[] = [$modelo, $registro];
        }

        return $guardados;
    }

    /**
     * @param  class-string<Model>  $clase
     * @param  list<string>  $uuids
     */
    private function borrar(string $clase, array $uuids): void
    {
        foreach ($clase::query()->whereIn('uuid', $uuids)->get() as $modelo) {
            if (! $modelo instanceof Movimiento) {
                $modelo->delete();

                continue;
            }

            // Sin los eventos del movimiento: lo que implica en su programado ya viene en el envío.
            $modelo->adjuntos()->get()->each->delete();
            Programacion::query()->where('movimiento_base_id', $modelo->id)->update(['movimiento_base_id' => null]);
            $modelo->deleteQuietly();
            $modelo->registrarEliminado();
        }
    }

    private function validar(array $cambios): void
    {
        foreach (self::TABLAS as $tabla => $config) {
            $reglas = ['id' => ['required', 'string', 'max:36'], ...$this->reglas($tabla)];

            foreach (['created', 'updated'] as $tipo) {
                foreach ($cambios[$tabla][$tipo] ?? [] as $registro) {
                    Validator::make(is_array($registro) ? $registro : [], $reglas)->validate();
                }
            }

            Validator::make($cambios[$tabla] ?? [], ['deleted' => ['sometimes', 'array'], 'deleted.*' => ['string', 'max:36']])->validate();
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function reglas(string $tabla): array
    {
        $uuid = ['nullable', 'string', 'max:36'];

        return match ($tabla) {
            'categorias' => [
                'tipo' => ['required', 'in:ingreso,gasto'],
                'nombre' => ['required', 'string', 'max:50'],
                'icono' => ['required', 'string', 'max:50'],
                'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            ],
            'programaciones' => [
                'movimiento_base_id' => $uuid,
                'intervalo' => ['required', 'integer', 'min:1', 'max:365'],
                'frecuencia' => ['required', 'in:dias,semanas,meses,anios'],
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['nullable', 'date_format:Y-m-d'],
                'ocurrencias' => ['required', 'integer', 'min:0'],
                'proxima_fecha' => ['required', 'date_format:Y-m-d'],
                'activo' => ['required', 'boolean'],
            ],
            'movimientos' => [
                'categoria_id' => $uuid,
                'programacion_id' => $uuid,
                'tipo' => ['required', 'in:ingreso,gasto'],
                'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
                'descripcion' => ['required', 'string', 'max:255'],
                'fecha' => ['required', 'date_format:Y-m-d'],
                'fecha_programada' => ['nullable', 'date_format:Y-m-d'],
            ],
        };
    }

    /**
     * El registro como lo guarda la app: uuid en vez de ids, fechas AAAA-MM-DD y marcas de tiempo en ms.
     *
     * @param  array<string, array<int, string>>  $uuids
     */
    private function paraApp(Model $registro, array $config, array $uuids): array
    {
        $fila = ['id' => $registro->uuid];

        foreach ($config['columnas'] as $columna) {
            $valor = $registro->{$columna};
            $fila[$columna] = match (true) {
                $valor instanceof CarbonInterface => $valor->toDateString(),
                $columna === 'monto' => (float) $valor,
                default => $valor,
            };
        }

        foreach ($config['relaciones'] as $columna => $destino) {
            $fila[$columna] = $uuids[$destino][$registro->{$columna}] ?? null;
        }

        foreach ($config['conteos'] ?? [] as $relacion) {
            $fila["{$relacion}_count"] = (int) $registro->{"{$relacion}_count"};
        }

        $fila['created_at'] = $registro->created_at?->getTimestampMs();
        $fila['updated_at'] = $registro->updated_at?->getTimestampMs();

        return $fila;
    }

    /**
     * id → uuid de los registros a los que apuntan las relaciones de $registros.
     *
     * @return array<string, array<int, string>>
     */
    private function uuidsRelacionados($registros, array $relaciones): array
    {
        $uuids = [];

        foreach ($relaciones as $columna => $destino) {
            $ids = $registros->pluck($columna)->filter()->unique();
            $uuids[$destino] = ($uuids[$destino] ?? []) + self::TABLAS[$destino]['modelo']::query()
                ->whereIn('id', $ids)->pluck('uuid', 'id')->all();
        }

        return $uuids;
    }

    private function idDe(string $tabla, string $uuid): ?int
    {
        return $this->ids[$tabla][$uuid] ??= self::TABLAS[$tabla]['modelo']::query()->where('uuid', $uuid)->value('id');
    }

    private function fecha(?int $ms): ?Carbon
    {
        return $ms ? Carbon::createFromTimestampMs($ms)->setTimezone(config('app.timezone'))->startOfSecond() : null;
    }

    private function conflicto(): never
    {
        abort(409, 'Hay cambios más recientes en el servidor: sincroniza de nuevo.');
    }
}
