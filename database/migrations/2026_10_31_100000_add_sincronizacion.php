<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tablas que la app guarda en su SQLite (WatermelonDB) y sincroniza con /api/sync.
     */
    private const TABLAS = ['categorias', 'programaciones', 'movimientos'];

    public function up(): void
    {
        // Id que genera la app para cada registro: así lo creado en el celular ya
        // tiene identidad antes de llegar al servidor y no choca entre dispositivos.
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('uuid', 36)->nullable()->after('id');
            });

            // Los movimientos creados sin conexión ya traen el suyo en cliente_id.
            DB::table($tabla)->whereNull('uuid')->orderBy('id')->lazyById()->each(function ($fila) use ($tabla) {
                DB::table($tabla)->where('id', $fila->id)->update([
                    'uuid' => $fila->cliente_id ?? (string) Str::uuid(),
                ]);
            });

            Schema::table($tabla, function (Blueprint $table) {
                $table->string('uuid', 36)->nullable(false)->change();
                $table->unique(['tenant_id', 'uuid']);
            });
        }

        // Lo que se borra deja aquí su rastro para que los demás dispositivos lo borren también.
        Schema::create('eliminados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tabla', 30);
            $table->string('uuid', 36);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eliminados');

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
