<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Lee CSV exportados de otras plataformas (bancos, apps, Excel) y convierte
 * cada fila en los datos de un movimiento según un mapeo de columnas.
 */
class ImportadorCsv
{
    public const MAX_FILAS = 5000;

    /**
     * Datos que se pueden tomar del CSV. Se usa "monto" (+ "tipo" o el signo) o "ingreso"/"gasto".
     * "adjunto" es el nombre del archivo dentro del ZIP/RAR (varios separados por |).
     * Programados: "programado" (SI/NO), "intervalo" (cada cuánto: 1, 2, 3…),
     * "periodo" (dias, semanas, meses, anios) y opcionalmente "hasta" una fecha.
     */
    public const CAMPOS = [
        'fecha', 'descripcion', 'monto', 'tipo', 'ingreso', 'gasto', 'categoria', 'adjunto',
        'programado', 'intervalo', 'periodo', 'hasta',
    ];

    public const FORMATOS_FECHA = ['Y-m-d', 'd/m/Y', 'm/d/Y'];

    /** Nombres de columna habituales para cada dato (sin tildes y en minúscula). */
    private const SINONIMOS = [
        'fecha' => ['fecha', 'date', 'dia', 'fecha transaccion', 'fecha movimiento', 'fecha operacion', 'transaction date'],
        'descripcion' => ['descripcion', 'concepto', 'detalle', 'description', 'memo', 'nota', 'referencia', 'nombre', 'payee'],
        'monto' => ['monto', 'valor', 'importe', 'amount', 'cantidad', 'total', 'value'],
        'tipo' => ['tipo', 'type', 'naturaleza', 'clase', 'movimiento'],
        'ingreso' => ['ingreso', 'ingresos', 'credito', 'creditos', 'abono', 'abonos', 'entrada', 'income', 'credit', 'deposito'],
        'gasto' => ['gasto', 'gastos', 'debito', 'debitos', 'cargo', 'cargos', 'salida', 'egreso', 'expense', 'debit', 'retiro'],
        'categoria' => ['categoria', 'category', 'rubro', 'grupo'],
        'adjunto' => ['adjunto', 'adjuntos', 'archivo', 'comprobante', 'soporte', 'attachment', 'file', 'foto', 'recibo'],
        'programado' => ['programado', 'es programado', 'programacion', 'recurrente', 'recurring'],
        'intervalo' => ['cada cuanto', 'cada', 'intervalo', 'cada cuantos', 'every'],
        'periodo' => ['tipo periodo', 'tipo de periodo', 'periodo', 'unidad', 'period'],
        'hasta' => ['hasta', 'fecha fin', 'fin', 'vence', 'repetir hasta', 'until', 'end date'],
    ];

    private const SI = ['si', 's', 'x', 'yes', 'y', 'true', '1', 'verdadero'];

    private const NO = ['', 'no', 'n', '0', 'false', 'falso', '-'];

    /** Valores aceptados en "tipo de periodo" (sin tildes, en minúscula). */
    private const PERIODOS = [
        'dias' => ['dia', 'dias', 'd', 'day', 'days'],
        'semanas' => ['semana', 'semanas', 'sem', 'week', 'weeks'],
        'meses' => ['mes', 'meses', 'm', 'month', 'months'],
        'anios' => ['anio', 'anios', 'ano', 'anos', 'a', 'year', 'years'],
    ];

    private const PALABRAS_INGRESO = ['ingreso', 'income', 'entrada', 'credito', 'abono', 'deposito', 'credit'];

    private const PALABRAS_GASTO = ['gasto', 'expense', 'egreso', 'salida', 'debito', 'cargo', 'retiro', 'pago', 'compra', 'debit'];

    /**
     * @return array{columnas: list<string>, filas: list<list<string>>}
     */
    public function leer(UploadedFile $archivo): array
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido); // BOM de Excel
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $primeraLinea = strtok($contenido, "\r\n") ?: '';
        $delimitador = $this->detectarDelimitador($primeraLinea);

        // fgetcsv respeta comillas y saltos de línea dentro de un campo.
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $filas = [];
        while (($fila = fgetcsv($flujo, null, $delimitador, '"', '')) !== false) {
            if ($fila === [null] || implode('', $fila) === '') {
                continue; // línea vacía
            }
            $filas[] = array_map(fn ($valor) => trim((string) $valor), $fila);
        }
        fclose($flujo);

        $encabezado = array_shift($filas) ?? [];

        return ['columnas' => $this->nombrarColumnas($encabezado), 'filas' => $filas];
    }

    /**
     * Adivina qué columna corresponde a cada dato a partir del encabezado.
     *
     * @param  list<string>  $columnas
     * @return array<string, ?string>
     */
    public function sugerirMapeo(array $columnas): array
    {
        $normalizadas = array_map($this->normalizar(...), $columnas);
        $mapeo = array_fill_keys(self::CAMPOS, null);
        $usadas = [];

        // Primero coincidencias exactas, luego columnas que contengan la palabra.
        foreach ([true, false] as $exacta) {
            foreach (self::SINONIMOS as $campo => $sinonimos) {
                if ($mapeo[$campo] !== null) {
                    continue;
                }
                foreach ($normalizadas as $i => $nombre) {
                    if (isset($usadas[$i])) {
                        continue;
                    }
                    $coincide = $exacta
                        ? in_array($nombre, $sinonimos, true)
                        : Str::contains($nombre, $sinonimos);
                    if ($coincide) {
                        $mapeo[$campo] = $columnas[$i];
                        $usadas[$i] = true;
                        break;
                    }
                }
            }
        }

        // Con columnas de crédito/débito no hace falta un monto único (y al revés).
        if ($mapeo['monto'] !== null && ($mapeo['ingreso'] !== null || $mapeo['gasto'] !== null)) {
            $mapeo['ingreso'] = $mapeo['gasto'] = null;
        }

        return $mapeo;
    }

    /**
     * @param  list<list<string>>  $filas
     */
    public function sugerirFormatoFecha(array $filas, ?int $columna): string
    {
        if ($columna === null) {
            return 'd/m/Y';
        }

        foreach (array_slice($filas, 0, 50) as $fila) {
            $partes = $this->partesFecha($fila[$columna] ?? '');
            if (! $partes) {
                continue;
            }
            if (strlen($partes[0]) === 4) {
                return 'Y-m-d';
            }
            if ((int) $partes[0] > 12) {
                return 'd/m/Y';
            }
            if ((int) $partes[1] > 12) {
                return 'm/d/Y';
            }
        }

        return 'd/m/Y'; // lo habitual en Latinoamérica
    }

    /**
     * Convierte una fila en datos de movimiento, o explica por qué no se puede.
     *
     * @param  list<string>  $fila
     * @param  list<string>  $columnas
     * @param  array<string, ?string>  $mapeo
     * @return array{0: ?array{fecha: string, descripcion: string, tipo: string, monto: float, categoria: ?string, adjuntos: list<string>, repetir: ?array{intervalo: int, frecuencia: string}, hasta: ?string}, 1: ?string}
     */
    public function interpretar(array $fila, array $columnas, array $mapeo, string $formatoFecha): array
    {
        $valor = function (string $campo) use ($fila, $columnas, $mapeo): string {
            $indice = $mapeo[$campo] === null ? false : array_search($mapeo[$campo], $columnas, true);

            return $indice === false ? '' : ($fila[$indice] ?? '');
        };

        $fecha = $this->fecha($valor('fecha'), $formatoFecha);
        if ($fecha === null) {
            return [null, 'Fecha inválida: "'.$valor('fecha').'"'];
        }

        $descripcion = Str::limit($valor('descripcion'), 250, '…');
        if ($descripcion === '') {
            return [null, 'Falta la descripción'];
        }

        if ($mapeo['monto'] !== null) {
            $monto = $this->monto($valor('monto'));
            if ($monto === null) {
                return [null, 'Monto inválido: "'.$valor('monto').'"'];
            }
            if ($mapeo['tipo'] !== null) {
                $tipo = $this->tipo($valor('tipo'));
                if ($tipo === null) {
                    return [null, 'Tipo no reconocido: "'.$valor('tipo').'" (usa ingreso o gasto)'];
                }
            } else {
                $tipo = $monto < 0 ? 'gasto' : 'ingreso'; // el signo decide
            }
        } else {
            $ingreso = abs($this->monto($valor('ingreso')) ?? 0);
            $gasto = abs($this->monto($valor('gasto')) ?? 0);
            [$tipo, $monto] = $ingreso > 0 ? ['ingreso', $ingreso] : ['gasto', $gasto];
        }

        $monto = round(abs($monto), 2);
        if ($monto == 0) {
            return [null, 'El monto es 0'];
        }

        [$repetir, $errorRepetir] = $this->programacion($valor('programado'), $valor('intervalo'), $valor('periodo'));
        if ($errorRepetir) {
            return [null, $errorRepetir];
        }

        $hasta = null;
        if ($repetir && $valor('hasta') !== '') {
            $hasta = $this->fecha($valor('hasta'), $formatoFecha);
            if ($hasta === null || $hasta <= $fecha) {
                return [null, 'Fecha "hasta" inválida o anterior a la fecha: "'.$valor('hasta').'"'];
            }
        }

        $categoria = $valor('categoria');
        $adjuntos = array_values(array_filter(array_map(
            ExtractorComprimido::nombreSeguro(...),
            preg_split('/[|\n]+/', $valor('adjunto')),
        )));

        return [[
            'fecha' => $fecha,
            'descripcion' => $descripcion,
            'tipo' => $tipo,
            'monto' => $monto,
            'categoria' => $categoria === '' ? null : Str::limit($categoria, 50, ''),
            'adjuntos' => $adjuntos,
            'repetir' => $repetir,
            'hasta' => $hasta,
        ], null];
    }

    /**
     * "$ 1.234.567,50" → 1234567.5 · "1,234.50" → 1234.5 · "(120.000)" → -120000
     */
    public function monto(string $texto): ?float
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        $negativo = str_starts_with($texto, '-') || str_ends_with($texto, '-')
            || (str_starts_with($texto, '(') && str_ends_with($texto, ')'));
        $numero = preg_replace('/[^\d.,]/', '', $texto);

        $punto = strrpos($numero, '.');
        $coma = strrpos($numero, ',');
        if ($punto !== false && $coma !== false) {
            // El último separador es el decimal; el otro, de miles.
            [$miles, $decimal] = $coma > $punto ? ['.', ','] : [',', '.'];
            $numero = str_replace([$miles, $decimal], ['', '.'], $numero);
        } elseif ($coma !== false) {
            $numero = preg_match('/^\d{1,3}(,\d{3})+$/', $numero)
                ? str_replace(',', '', $numero)
                : str_replace(',', '.', $numero);
        } elseif ($punto !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $numero)) {
            $numero = str_replace('.', '', $numero); // "120.000" son miles (pesos)
        }

        if (! is_numeric($numero)) {
            return null;
        }

        return ($negativo ? -1 : 1) * (float) $numero;
    }

    /**
     * programado=SI, cada cuánto=2, tipo de periodo=meses → [intervalo 2, meses].
     * programado vacío o NO → sin programación (las otras columnas se ignoran).
     *
     * @return array{0: ?array{intervalo: int, frecuencia: string}, 1: ?string}  [regla, error]
     */
    public function programacion(string $programado, string $cadaCuanto, string $periodo): array
    {
        $programado = $this->normalizar($programado);
        if (in_array($programado, self::NO, true)) {
            return [null, null];
        }
        if (! in_array($programado, self::SI, true)) {
            return [null, "Programado debe ser SI o NO (dice \"{$programado}\")"];
        }

        $cadaCuanto = trim($cadaCuanto);
        if ($cadaCuanto !== '' && (! ctype_digit($cadaCuanto) || (int) $cadaCuanto < 1 || (int) $cadaCuanto > 365)) {
            return [null, "Cada cuánto debe ser un número entero entre 1 y 365 (dice \"{$cadaCuanto}\")"];
        }

        $normalizado = $this->normalizar($periodo);
        $frecuencia = null;
        foreach (self::PERIODOS as $valor => $sinonimos) {
            if (in_array($normalizado, $sinonimos, true)) {
                $frecuencia = $valor;
            }
        }
        if ($frecuencia === null) {
            return [null, $normalizado === ''
                ? 'Falta el tipo de periodo: dias, semanas, meses o anios'
                : "Tipo de periodo no válido: \"{$periodo}\" (usa dias, semanas, meses o anios)"];
        }

        return [['intervalo' => $cadaCuanto === '' ? 1 : (int) $cadaCuanto, 'frecuencia' => $frecuencia], null];
    }

    private function tipo(string $texto): ?string
    {
        $signo = trim($texto); // antes de normalizar, que convierte "-" en espacio
        $texto = $this->normalizar($texto);

        return match (true) {
            $signo === '+' || Str::contains($texto, self::PALABRAS_INGRESO) => 'ingreso',
            $signo === '-' || Str::contains($texto, self::PALABRAS_GASTO) => 'gasto',
            default => null,
        };
    }

    private function fecha(string $texto, string $formato): ?string
    {
        $partes = $this->partesFecha($texto);
        if (! $partes) {
            return null;
        }

        [$anio, $mes, $dia] = match (true) {
            strlen($partes[0]) === 4 => $partes, // AAAA-MM-DD sirve con cualquier formato
            $formato === 'm/d/Y' => [$partes[2], $partes[0], $partes[1]],
            default => [$partes[2], $partes[1], $partes[0]],
        };
        if (strlen($anio) === 2) {
            $anio = "20{$anio}";
        }

        if (! checkdate((int) $mes, (int) $dia, (int) $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /** "2026-10-05 08:30" / "05/10/2026" / "5.10.26" → ['2026','10','05'] / ['05','10','2026'] */
    private function partesFecha(string $texto): ?array
    {
        return preg_match('/^(\d{1,4})[-\/.](\d{1,2})[-\/.](\d{2,4})/', trim($texto), $m) ? [$m[1], $m[2], $m[3]] : null;
    }

    private function detectarDelimitador(string $linea): string
    {
        $conteos = [];
        foreach ([',', ';', "\t", '|'] as $delimitador) {
            $conteos[$delimitador] = substr_count($linea, $delimitador);
        }
        arsort($conteos);

        return array_key_first($conteos);
    }

    /**
     * @param  list<?string>  $encabezado
     * @return list<string>
     */
    private function nombrarColumnas(array $encabezado): array
    {
        $nombres = [];
        foreach ($encabezado as $i => $nombre) {
            $nombre = trim((string) $nombre) ?: 'Columna '.($i + 1);
            $base = $nombre;
            for ($n = 2; in_array($nombre, $nombres, true); $n++) {
                $nombre = "{$base} ({$n})";
            }
            $nombres[] = $nombre;
        }

        return $nombres;
    }

    public function normalizar(string $texto): string
    {
        // "Tipo_Periodo" / "tipo-periodo" / "Tipo período" → "tipo periodo"
        return Str::of($texto)->ascii()->lower()->replace(['_', '-'], ' ')->squish()->toString();
    }
}
