<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Id que genera la app para lo creado sin conexión: si el envío se repite
        // (se cayó la señal justo después de guardar), no se duplica.
        Schema::table('movimientos', function (Blueprint $table) {
            $table->uuid('cliente_id')->nullable()->after('id');
            $table->unique(['tenant_id', 'cliente_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'cliente_id']);
            $table->dropColumn('cliente_id');
        });
    }
};
