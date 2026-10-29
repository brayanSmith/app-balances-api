<?php

namespace App\Services;

use App\Models\Adjunto;
use App\Models\Categoria;
use App\Models\Eliminado;
use App\Models\Importacion;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra los datos de un tenant: movimientos, comprobantes (con sus archivos),
 * programados, importaciones y categorías. Los usuarios y sus sesiones se conservan.
 */
class VaciadorDeDatos
{
    /**
     * @param  bool  $avisarBorrados  dejar el rastro en eliminados para que los teléfonos (/api/sync) también los borren
     * @return array<string, int> cuántos registros se borraron de cada tabla
     */
    public function vaciar(Tenant $tenant, bool $avisarBorrados, ?callable $despues = null): array
    {
        $borrados = DB::transaction(function () use ($tenant, $avisarBorrados, $despues) {
            // Sin el scope global para que quede explícito qué tenant se borra.
            $delTenant = fn (string $modelo) => $modelo::withoutGlobalScopes()->where('tenant_id', $tenant->id);

            // El borrado masivo no dispara eventos: el rastro para /api/sync se deja a mano.
            if ($avisarBorrados) {
                foreach ([Movimiento::class, Programacion::class, Categoria::class] as $modelo) {
                    $modelo::registrarEliminados($delTenant($modelo));
                }
            } else {
                $delTenant(Eliminado::class)->delete();
            }

            // Orden: primero lo que depende de otros registros.
            $borrados = [
                'adjuntos' => $delTenant(Adjunto::class)->delete(),
                'movimientos' => $delTenant(Movimiento::class)->delete(),
                'programaciones' => $delTenant(Programacion::class)->delete(),
                'importaciones' => $delTenant(Importacion::class)->delete(),
                'categorias' => $delTenant(Categoria::class)->delete(),
            ];

            if ($despues) {
                $despues();
            }

            return $borrados;
        });

        // Los archivos, una vez confirmado el borrado en la base de datos.
        Storage::disk()->deleteDirectory("adjuntos/{$tenant->id}");
        Storage::disk()->deleteDirectory("tmp/importaciones/{$tenant->id}");

        return $borrados;
    }
}
