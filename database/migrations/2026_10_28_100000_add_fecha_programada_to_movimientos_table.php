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
        // Vencimiento que cubre un pago de un programado (la "fecha" es cuándo se pagó).
        Schema::table('movimientos', function (Blueprint $table) {
            $table->date('fecha_programada')->nullable()->after('fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropColumn('fecha_programada');
        });
    }
};
