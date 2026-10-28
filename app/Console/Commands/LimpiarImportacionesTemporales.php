<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LimpiarImportacionesTemporales extends Command
{
    protected $signature = 'importaciones:limpiar {--horas=24 : Antigüedad mínima para borrar}';

    protected $description = 'Borra los comprimidos extraídos para importar que ya no se usaron';

    public function handle(): int
    {
        $disco = Storage::disk();
        $limite = now()->subHours((int) $this->option('horas'))->getTimestamp();
        $borradas = 0;

        foreach ($disco->directories('tmp/importaciones') as $carpetaTenant) {
            foreach ($disco->directories($carpetaTenant) as $carpeta) {
                // En un bucket las carpetas no tienen fecha: cuenta la del archivo más reciente.
                $ultimo = collect($disco->files($carpeta))->map(fn ($ruta) => $disco->lastModified($ruta))->max() ?? 0;
                if ($ultimo < $limite) {
                    $disco->deleteDirectory($carpeta);
                    $borradas++;
                }
            }
        }

        $this->info("Carpetas temporales borradas: {$borradas}");

        return self::SUCCESS;
    }
}
