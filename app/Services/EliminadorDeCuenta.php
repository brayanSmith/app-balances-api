<?php

namespace App\Services;

use App\Models\Adjunto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra una cuenta y todos sus datos (lo exige Google Play). Lo usan la app
 * (DELETE /api/cuenta) y la página web /eliminar-cuenta.
 */
class EliminadorDeCuenta
{
    public function eliminar(User $user): void
    {
        $tenant = $user->tenant;
        // Hoy cada registro crea su propio tenant; si algún día se comparte, solo se va lo de esta persona.
        $esElUnico = $tenant->users()->count() === 1;

        // Rutas de los comprobantes: la base los borra en cascada, pero los archivos no.
        $archivos = $esElUnico
            ? []
            : Adjunto::withoutGlobalScopes()
                ->whereIn('movimiento_id', $user->movimientos()->withoutGlobalScopes()->select('id'))
                ->pluck('ruta')
                ->all();

        DB::transaction(function () use ($user, $tenant, $esElUnico) {
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // El tenant arrastra en cascada usuarios, movimientos, categorías, programados,
            // importaciones y comprobantes.
            $esElUnico ? $tenant->delete() : $user->delete();
        });

        // Los archivos, una vez confirmado el borrado en la base de datos.
        $disco = Storage::disk();
        $disco->deleteDirectory("fotos/{$user->id}");
        if ($esElUnico) {
            $disco->deleteDirectory("adjuntos/{$tenant->id}");
            $disco->deleteDirectory("tmp/importaciones/{$tenant->id}");
        } else {
            $disco->delete($archivos);
        }
    }
}
