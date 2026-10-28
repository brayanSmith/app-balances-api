<?php

namespace App\Services;

use Illuminate\Http\File as HttpFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Extrae de un ZIP (ZipArchive, incluido en PHP) o RAR (con 7-Zip instalado en
 * el servidor) las imágenes y PDF, todos en una sola carpeta y sin subcarpetas.
 */
class ExtractorComprimido
{
    public const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    public const MAX_ARCHIVO = 10 * 1024 * 1024; // igual que un adjunto normal

    public const MAX_TOTAL = 500 * 1024 * 1024; // descomprimido: protege de "zip bombs"

    public const MAX_ENTRADAS = 5000;

    /**
     * Extrae en una carpeta temporal del servidor (ZipArchive y 7-Zip necesitan rutas
     * reales) y sube lo extraído a $destino en el disco por defecto, que en Laravel
     * Cloud es el bucket: así lo encuentra la petición de importar aunque la atienda
     * otra instancia.
     *
     * @param  string  $destino  carpeta en el disco por defecto
     * @return array{archivos: int, omitidos: list<string>}
     */
    public function extraer(UploadedFile $comprimido, string $destino): array
    {
        $carpeta = sys_get_temp_dir().'/balances-extraccion-'.Str::random(16);
        File::ensureDirectoryExists($carpeta);

        try {
            $resultado = match (strtolower($comprimido->getClientOriginalExtension())) {
                'zip' => $this->extraerZip($comprimido->getRealPath(), $carpeta),
                'rar' => $this->extraerRar($comprimido->getRealPath(), $carpeta),
                default => throw ValidationException::withMessages(['comprimido' => ['Sube un archivo ZIP o RAR.']]),
            };

            foreach (File::files($carpeta) as $archivo) {
                Storage::disk()->putFileAs($destino, new HttpFile($archivo->getPathname()), $archivo->getFilename());
            }

            return $resultado;
        } finally {
            File::deleteDirectory($carpeta);
        }
    }

    /**
     * Nombre de archivo sin rutas ni caracteres problemáticos. Se usa igual para
     * guardar lo extraído y para buscar lo que el CSV nombra, así coinciden.
     */
    public static function nombreSeguro(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', trim($nombre)));
        if (! mb_check_encoding($nombre, 'UTF-8')) {
            $nombre = mb_convert_encoding($nombre, 'UTF-8', 'CP850'); // ZIP creados en Windows
        }

        return trim(preg_replace('/[\x00-\x1F:*?"<>|]/u', '_', $nombre));
    }

    /**
     * @return array{archivos: int, omitidos: list<string>}
     */
    private function extraerZip(string $ruta, string $carpeta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['comprimido' => ['El ZIP está dañado o no se puede abrir.']]);
        }
        if ($zip->numFiles > self::MAX_ENTRADAS) {
            throw ValidationException::withMessages(['comprimido' => ['El ZIP tiene más de '.self::MAX_ENTRADAS.' archivos.']]);
        }

        $guardados = [];
        $omitidos = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $info = $zip->statIndex($i);
            if ($info === false || str_ends_with($info['name'], '/') || str_starts_with($info['name'], '__MACOSX/')) {
                continue; // carpetas y basura de macOS
            }

            $nombre = self::nombreSeguro($info['name']);
            if ($motivo = $this->motivoParaOmitir($nombre, $info['size'], $guardados)) {
                $motivo === 'oculto' || $omitidos[] = "{$nombre} ({$motivo})";

                continue;
            }

            $total += $info['size'];
            if ($total > self::MAX_TOTAL) {
                throw ValidationException::withMessages(['comprimido' => ['El contenido descomprimido supera 500 MB.']]);
            }

            // Se copia por flujo a un nombre propio: el ZIP nunca decide la ruta de destino.
            $origen = $zip->getStream($info['name']);
            $salida = fopen("{$carpeta}/{$nombre}", 'wb');
            $copiados = stream_copy_to_stream($origen, $salida, self::MAX_ARCHIVO + 1);
            fclose($origen);
            fclose($salida);
            if ($copiados > self::MAX_ARCHIVO) {
                unlink("{$carpeta}/{$nombre}"); // el tamaño declarado mentía
                $omitidos[] = "{$nombre} (más de 10 MB)";

                continue;
            }

            $guardados[mb_strtolower($nombre)] = true;
        }
        $zip->close();

        return ['archivos' => count($guardados), 'omitidos' => $omitidos];
    }

    /**
     * @return array{archivos: int, omitidos: list<string>}
     */
    private function extraerRar(string $ruta, string $carpeta): array
    {
        $sieteZip = $this->rutaSieteZip() ?? throw ValidationException::withMessages(['comprimido' => [
            'Para leer archivos RAR el servidor necesita 7-Zip. Mientras tanto, comprime los adjuntos en ZIP.',
        ]]);

        // 1. Revisar el contenido antes de extraer (tamaño total).
        $lista = new Process([$sieteZip, 'l', '-slt', '-p-', $ruta]);
        $lista->setTimeout(60)->run();
        if (! $lista->isSuccessful()) {
            throw ValidationException::withMessages(['comprimido' => ['El RAR está dañado, protegido con contraseña o no se puede abrir.']]);
        }
        preg_match_all('/^Size = (\d+)$/m', $lista->getOutput(), $tamanos);
        if (array_sum($tamanos[1]) > self::MAX_TOTAL || count($tamanos[1]) > self::MAX_ENTRADAS) {
            throw ValidationException::withMessages(['comprimido' => ['El RAR es demasiado grande (máx. 500 MB descomprimido y '.self::MAX_ENTRADAS.' archivos).']]);
        }

        // 2. Extraer "aplanado" (e = sin carpetas) a una carpeta aparte y filtrar.
        $temporal = "{$carpeta}/.rar-".Str::random(8);
        File::ensureDirectoryExists($temporal);
        $extraccion = new Process([$sieteZip, 'e', '-y', '-p-', "-o{$temporal}", $ruta]);
        $extraccion->setTimeout(300)->run();

        $guardados = [];
        $omitidos = [];
        foreach (File::files($temporal) as $archivo) {
            $nombre = self::nombreSeguro($archivo->getFilename());
            if ($motivo = $this->motivoParaOmitir($nombre, $archivo->getSize(), $guardados)) {
                $motivo === 'oculto' || $omitidos[] = "{$nombre} ({$motivo})";

                continue;
            }
            File::move($archivo->getPathname(), "{$carpeta}/{$nombre}");
            $guardados[mb_strtolower($nombre)] = true;
        }
        File::deleteDirectory($temporal);

        if (! $extraccion->isSuccessful() && ! $guardados) {
            throw ValidationException::withMessages(['comprimido' => ['No se pudo extraer el RAR (¿tiene contraseña?).']]);
        }

        return ['archivos' => count($guardados), 'omitidos' => $omitidos];
    }

    /**
     * @param  array<string, true>  $guardados
     */
    private function motivoParaOmitir(string $nombre, int $tamano, array $guardados): ?string
    {
        return match (true) {
            $nombre === '' || str_starts_with($nombre, '.') => 'oculto',
            ! in_array(strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), self::EXTENSIONES, true) => 'no es imagen ni PDF',
            $tamano > self::MAX_ARCHIVO => 'más de 10 MB',
            isset($guardados[mb_strtolower($nombre)]) => 'nombre repetido',
            default => null,
        };
    }

    /** 7-Zip: SEVEN_ZIP_PATH en .env, la instalación habitual o el PATH. */
    private function rutaSieteZip(): ?string
    {
        $candidatos = array_filter([
            config('services.siete_zip.ruta'),
            'C:\\Program Files\\7-Zip\\7z.exe',
            'C:\\Program Files (x86)\\7-Zip\\7z.exe',
        ]);
        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        $buscador = new ExecutableFinder;

        return $buscador->find('7z') ?? $buscador->find('7zz');
    }
}
