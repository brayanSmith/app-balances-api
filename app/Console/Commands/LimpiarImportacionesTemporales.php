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
        $disco = Storage::disk('local');
        $limite = now()->subHours((int) $this->option('horas'))->getTimestamp();
        $borradas = 0;

        foreach ($disco->directories('tmp/importaciones') as $carpetaTenant) {
            foreach ($disco->directories($carpetaTenant) as $carpeta) {
                if (filemtime($disco->path($carpeta)) < $limite) {
                    $disco->deleteDirectory($carpeta);
                    $borradas++;
                }
            }
        }

        $this->info("Carpetas temporales borradas: {$borradas}");

        return self::SUCCESS;
    }
}
