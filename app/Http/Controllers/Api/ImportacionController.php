<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportacionResource;
use App\Models\Categoria;
use App\Models\Importacion;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Services\ExtractorComprimido;
use App\Services\ImportadorCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportacionController extends Controller
{
    public function __construct(private ImportadorCsv $importador) {}

    /**
     * Últimas importaciones, para poder deshacerlas.
     */
    public function index(): AnonymousResourceCollection
    {
        return ImportacionResource::collection(Importacion::query()->latest('id')->limit(20)->get());
    }

    /**
     * Lee el CSV y devuelve sus columnas, el mapeo sugerido (o el enviado) y
     * cómo quedarían las primeras filas. No guarda nada.
     */
    public function previsualizar(Request $request): JsonResponse
    {
        [$csv, $mapeo, $formato, $disponibles] = $this->leerSolicitud($request);

        $validas = 0;
        $vistaPrevia = [];
        $referenciados = 0;
        $faltantes = [];
        foreach ($csv['filas'] as $i => $fila) {
            [$datos, $error] = $this->importador->interpretar($fila, $csv['columnas'], $mapeo, $formato);
            $validas += $datos ? 1 : 0;

            $adjuntos = [];
            foreach ($datos['adjuntos'] ?? [] as $nombre) {
                $encontrado = isset($disponibles[mb_strtolower($nombre)]);
                $adjuntos[] = ['nombre' => $nombre, 'encontrado' => $encontrado];
                $referenciados++;
                $encontrado || $faltantes[] = ['fila' => $i + 2, 'nombre' => $nombre];
            }

            if ($i < 5) {
                $vistaPrevia[] = ['fila' => $i + 2, 'datos' => $datos, 'error' => $error, 'adjuntos' => $adjuntos];
            }
        }

        // Hasta 3 valores de ejemplo por columna, para reconocerla aunque el encabezado no diga nada.
        $muestras = [];
        foreach ($csv['columnas'] as $c => $columna) {
            $valores = array_filter(array_column(array_slice($csv['filas'], 0, 30), $c), fn ($v) => $v !== '');
            $muestras[$columna] = array_map(
                fn ($v) => Str::limit($v, 40),
                array_slice(array_values(array_unique($valores)), 0, 3),
            );
        }

        return response()->json([
            'columnas' => $csv['columnas'],
            'muestras' => $muestras,
            'mapeo' => $mapeo,
            'formato_fecha' => $formato,
            'total_filas' => count($csv['filas']),
            'validas' => $validas,
            'con_error' => count($csv['filas']) - $validas,
            'vista_previa' => $vistaPrevia,
            'adjuntos' => [
                'referenciados' => $referenciados,
                'encontrados' => $referenciados - count($faltantes),
                'faltantes' => array_slice($faltantes, 0, 50),
            ],
        ]);
    }

    /**
     * Importa el CSV. Si hay filas con error no importa nada (salvo omitir_errores=1).
     * Las filas que ya existen (misma fecha, tipo, monto y descripción) se omiten,
     * así que importar dos veces el mismo archivo no duplica.
     */
    public function store(Request $request): JsonResponse
    {
        [$csv, $mapeo, $formato, $disponibles, $carpeta] = $this->leerSolicitud($request);

        if (count($csv['filas']) > ImportadorCsv::MAX_FILAS) {
            throw ValidationException::withMessages([
                'archivo' => ['El archivo tiene más de '.ImportadorCsv::MAX_FILAS.' filas. Divídelo en partes.'],
            ]);
        }

        $validas = [];
        $errores = [];
        foreach ($csv['filas'] as $i => $fila) {
            [$datos, $error] = $this->importador->interpretar($fila, $csv['columnas'], $mapeo, $formato);
            $datos ? $validas[] = $datos + ['fila' => $i + 2] : $errores[] = ['fila' => $i + 2, 'error' => $error];
        }

        if ($errores && ! $request->boolean('omitir_errores')) {
            return response()->json([
                'message' => count($errores).' filas tienen errores. Corrígelas o impórtalas omitiendo esas filas.',
                'errores' => array_slice($errores, 0, 50),
                'con_error' => count($errores),
                'validas' => count($validas),
            ], 422);
        }

        if (! $validas) {
            throw ValidationException::withMessages(['archivo' => ['No hay filas válidas para importar.']]);
        }

        // Si algo falla, se borran los adjuntos ya copiados (la BD hace rollback sola).
        $copiados = [];
        try {
            $resultado = DB::transaction(fn () => $this->importar(
                $request,
                $validas,
                count($csv['filas']),
                count($errores),
                $carpeta,
                $disponibles,
                $copiados,
            ));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($copiados);
            throw $e;
        }

        if ($carpeta) {
            Storage::disk('local')->deleteDirectory($carpeta);
        }

        return response()->json($resultado, 201);
    }

    /**
     * Recibe el ZIP/RAR con los adjuntos y lo extrae a una carpeta temporal.
     * Devuelve un token para usarlo en previsualizar/importar sin volver a subirlo.
     */
    public function subirComprimido(Request $request, ExtractorComprimido $extractor): JsonResponse
    {
        $request->validate([
            'comprimido' => ['required', 'file', 'max:102400', 'extensions:zip,rar'],
        ], [
            'comprimido.extensions' => 'Sube un archivo ZIP o RAR.',
            'comprimido.max' => 'El comprimido no puede pesar más de 100 MB.',
        ]);

        $token = (string) Str::uuid();
        $carpeta = $this->carpetaTemporal($request, $token);

        try {
            $resultado = $extractor->extraer($request->file('comprimido'), $carpeta);
        } catch (Throwable $e) {
            Storage::disk('local')->deleteDirectory($carpeta);
            throw $e;
        }

        if ($resultado['archivos'] === 0) {
            Storage::disk('local')->deleteDirectory($carpeta);
            throw ValidationException::withMessages(['comprimido' => ['El comprimido no tiene imágenes ni PDF.']]);
        }

        return response()->json([
            'token' => $token,
            'nombre' => $request->file('comprimido')->getClientOriginalName(),
            'archivos' => $resultado['archivos'],
            'omitidos' => array_slice($resultado['omitidos'], 0, 50),
        ], 201);
    }

    /**
     * Deshace una importación: elimina los movimientos que creó.
     */
    public function destroy(Importacion $importacion): Response
    {
        DB::transaction(function () use ($importacion) {
            $importacion->movimientos()->get()->each->delete();
            $importacion->delete();
        });

        return response()->noContent();
    }

    /**
     * CSV de ejemplo con el formato más simple.
     */
    public function plantilla(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo "\xEF\xBB\xBF"; // BOM: Excel lo abre con tildes correctas
            echo "fecha,descripcion,monto,tipo,categoria,adjunto,programado,cada_cuanto,tipo_periodo,hasta\n";
            echo "2026-10-01,Pago de nómina,3000000,ingreso,Salario,,SI,1,meses,\n";
            echo "2026-10-05,Mercado de la semana,250000,gasto,Comida,factura-mercado.jpg,NO,,,\n";
            echo "2026-10-10,Recibo de energía,120000,gasto,Servicios,80080fd6.Foto_pago.223121.jpg|recibo-energia.pdf,SI,1,meses,\n";
            echo "2026-10-12,Gimnasio,90000,gasto,Salud,,SI,2,semanas,\n";
            echo "2026-10-15,Cuota del carro,850000,gasto,Transporte,,SI,1,meses,2027-12-15\n";
        }, 'plantilla-balances.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<array{fila: int, fecha: string, descripcion: string, tipo: string, monto: float, categoria: ?string, adjuntos: list<string>}>  $filas
     * @param  array<string, string>  $disponibles  nombre en minúscula → nombre en la carpeta temporal
     * @param  list<string>  $copiados  rutas de adjuntos creadas (para limpiar si algo falla)
     * @return array<string, mixed>
     */
    private function importar(
        Request $request,
        array $filas,
        int $total,
        int $omitidos,
        ?string $carpeta,
        array $disponibles,
        array &$copiados,
    ): array {
        $importacion = new Importacion([
            'archivo' => $request->file('archivo')->getClientOriginalName(),
            'filas' => $total,
            'importados' => 0,
            'duplicados' => 0,
            'omitidos' => $omitidos,
        ]);
        $importacion->user()->associate($request->user())->save();

        // Movimientos que ya existen en el rango del archivo, contados por clave.
        $fechas = array_column($filas, 'fecha');
        $existentes = Movimiento::query()
            ->whereDate('fecha', '>=', min($fechas))
            ->whereDate('fecha', '<=', max($fechas))
            ->get(['fecha', 'tipo', 'monto', 'descripcion'])
            ->countBy(fn (Movimiento $m) => $this->clave($m->fecha->toDateString(), $m->tipo, (float) $m->monto, $m->descripcion))
            ->all();

        $categorias = Categoria::query()->get()
            ->keyBy(fn (Categoria $c) => $c->tipo.'|'.$this->importador->normalizar($c->nombre));
        $creadas = [];
        $importados = 0;
        $duplicados = 0;
        $adjuntosImportados = 0;
        $adjuntosFaltantes = [];
        $series = []; // filas programadas agrupadas por serie (tipo + descripción)

        foreach ($filas as $fila) {
            $clave = $this->clave($fila['fecha'], $fila['tipo'], $fila['monto'], $fila['descripcion']);
            if (($existentes[$clave] ?? 0) > 0) {
                $existentes[$clave]--;
                $duplicados++;

                continue;
            }

            $categoriaId = null;
            if ($fila['categoria'] !== null) {
                $claveCategoria = $fila['tipo'].'|'.$this->importador->normalizar($fila['categoria']);
                if (! $categorias->has($claveCategoria)) {
                    $categorias[$claveCategoria] = Categoria::create([
                        'tipo' => $fila['tipo'],
                        'nombre' => $fila['categoria'],
                        'icono' => 'pricetag',
                        'color' => '#64748B',
                    ]);
                    $creadas[] = $fila['categoria'];
                }
                $categoriaId = $categorias[$claveCategoria]->id;
            }

            $movimiento = Movimiento::forceCreate([
                'tenant_id' => $importacion->tenant_id,
                'user_id' => $request->user()->id,
                'importacion_id' => $importacion->id,
                'tipo' => $fila['tipo'],
                'monto' => $fila['monto'],
                'descripcion' => $fila['descripcion'],
                'categoria_id' => $categoriaId,
                'fecha' => $fila['fecha'],
            ]);
            $importados++;

            if ($fila['repetir']) {
                $series[$fila['tipo'].'|'.$this->importador->normalizar($fila['descripcion'])][] = [$movimiento, $fila];
            }

            foreach ($fila['adjuntos'] as $nombre) {
                $real = $disponibles[mb_strtolower($nombre)] ?? null;
                if (! $real) {
                    $adjuntosFaltantes[] = ['fila' => $fila['fila'], 'nombre' => $nombre];

                    continue;
                }
                $destino = "adjuntos/{$importacion->tenant_id}/".Str::random(40).'.'.strtolower(pathinfo($real, PATHINFO_EXTENSION));
                Storage::disk('local')->copy("{$carpeta}/{$real}", $destino);
                $copiados[] = $destino;

                $movimiento->adjuntos()->create([
                    'nombre' => $real,
                    'ruta' => $destino,
                    'mime' => Storage::disk('local')->mimeType($destino) ?: 'application/octet-stream',
                    'tamano' => Storage::disk('local')->size($destino),
                ]);
                $adjuntosImportados++;
            }
        }

        $programacionesCreadas = $this->programarSeries($series);

        $importacion->update(['importados' => $importados, 'duplicados' => $duplicados]);

        return [
            'importacion' => new ImportacionResource($importacion),
            'importados' => $importados,
            'duplicados' => $duplicados,
            'omitidos' => $omitidos,
            'categorias_creadas' => $creadas,
            'adjuntos_importados' => $adjuntosImportados,
            'adjuntos_faltantes' => array_slice($adjuntosFaltantes, 0, 50),
            'programaciones_creadas' => $programacionesCreadas,
        ];
    }

    /**
     * Las filas programadas con la misma descripción y tipo son una serie (p. ej. el
     * arriendo de agosto, septiembre y octubre): se crea UNA programación desde el pago
     * más reciente y las anteriores quedan enlazadas a ella. Si ya existe una
     * programación para esa serie, se reutiliza en vez de duplicarla.
     *
     * @param  array<string, list<array{0: Movimiento, 1: array<string, mixed>}>>  $series
     * @return int programaciones nuevas
     */
    private function programarSeries(array $series): int
    {
        if (! $series) {
            return 0;
        }

        $existentes = Programacion::query()->with('base')->get()
            ->filter(fn (Programacion $p) => $p->base !== null)
            ->keyBy(fn (Programacion $p) => $p->base->tipo.'|'.$this->importador->normalizar($p->base->descripcion));
        $creadas = 0;

        foreach ($series as $clave => $miembros) {
            usort($miembros, fn ($a, $b) => [$a[1]['fecha'], $a[0]->id] <=> [$b[1]['fecha'], $b[0]->id]);
            [$ultimo, $filaUltima] = end($miembros);
            $regla = [
                'intervalo' => $filaUltima['repetir']['intervalo'],
                'frecuencia' => $filaUltima['repetir']['frecuencia'],
                'fecha_fin' => $filaUltima['hasta'],
            ];

            $programacion = $existentes[$clave] ?? null;
            $esMasReciente = $programacion
                && $filaUltima['fecha'] > $programacion->ultimoMovimiento()?->fecha->toDateString();

            if (! $programacion) {
                $programacion = Programacion::crearDesde($ultimo, $regla);
                $creadas++;
            }
            foreach ($miembros as [$movimiento]) {
                $movimiento->programacion()->associate($programacion)->save();
            }

            // El CSV trae un pago más nuevo que la programación existente: pasa a ser
            // la base y el calendario se recalcula desde él.
            if ($esMasReciente) {
                $programacion->fill($regla)->base()->associate($ultimo);
                $programacion->reprogramar();
                $programacion->save();
            }
        }

        return $creadas;
    }

    private function clave(string $fecha, string $tipo, float $monto, string $descripcion): string
    {
        return implode('|', [$fecha, $tipo, number_format($monto, 2, '.', ''), $this->importador->normalizar($descripcion)]);
    }

    /**
     * Valida el archivo y el mapeo. Si no se envía mapeo/formato, usa los sugeridos.
     *
     * @return array{0: array{columnas: list<string>, filas: list<list<string>>}, 1: array<string, ?string>, 2: string, 3: array<string, string>, 4: ?string}
     */
    private function leerSolicitud(Request $request): array
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120', 'extensions:csv,txt'],
            'mapeo' => ['nullable', 'array'],
            'mapeo.*' => ['nullable', 'string'],
            'formato_fecha' => ['nullable', 'in:'.implode(',', ImportadorCsv::FORMATOS_FECHA)],
            'comprimido' => ['nullable', 'uuid'],
        ], [
            'archivo.extensions' => 'El archivo debe ser un CSV.',
            'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
        ]);

        $csv = $this->importador->leer($request->file('archivo'));
        if (count($csv['columnas']) < 2 || ! $csv['filas']) {
            throw ValidationException::withMessages([
                'archivo' => ['No se encontraron datos. El CSV debe tener una fila de encabezados y al menos una fila de datos.'],
            ]);
        }

        $sugerido = $this->importador->sugerirMapeo($csv['columnas']);
        $mapeo = $request->has('mapeo')
            ? array_merge(array_fill_keys(ImportadorCsv::CAMPOS, null), array_intersect_key(
                array_map(fn ($c) => in_array($c, $csv['columnas'], true) ? $c : null, (array) $request->input('mapeo')),
                array_flip(ImportadorCsv::CAMPOS),
            ))
            : $sugerido;

        $columnaFecha = $mapeo['fecha'] === null ? null : array_search($mapeo['fecha'], $csv['columnas'], true);
        $formato = $request->input('formato_fecha')
            ?? $this->importador->sugerirFormatoFecha($csv['filas'], $columnaFecha === false ? null : $columnaFecha);

        if ($request->routeIs('importaciones.store')) {
            $faltan = [];
            if (! $mapeo['fecha']) {
                $faltan[] = 'la fecha';
            }
            if (! $mapeo['descripcion']) {
                $faltan[] = 'la descripción';
            }
            if (! $mapeo['monto'] && ! $mapeo['ingreso'] && ! $mapeo['gasto']) {
                $faltan[] = 'el monto (o las columnas de ingreso/gasto)';
            }
            if ($faltan) {
                throw ValidationException::withMessages(['mapeo' => ['Indica qué columna tiene '.implode(', ', $faltan).'.']]);
            }
        }

        // Adjuntos del comprimido subido antes (nombre en minúscula → nombre real).
        $carpeta = null;
        $disponibles = [];
        if ($token = $request->input('comprimido')) {
            $carpeta = $this->carpetaTemporal($request, $token);
            if (! Storage::disk('local')->directoryExists($carpeta)) {
                throw ValidationException::withMessages(['comprimido' => ['El comprimido ya no está disponible. Súbelo de nuevo.']]);
            }
            foreach (Storage::disk('local')->files($carpeta) as $ruta) {
                $disponibles[mb_strtolower(basename($ruta))] = basename($ruta);
            }
        }

        return [$csv, $mapeo, $formato, $disponibles, $carpeta];
    }

    /** Carpeta temporal del comprimido, dentro de la del tenant (otro tenant no puede usar el token). */
    private function carpetaTemporal(Request $request, string $token): string
    {
        return "tmp/importaciones/{$request->user()->tenant_id}/{$token}";
    }
}
