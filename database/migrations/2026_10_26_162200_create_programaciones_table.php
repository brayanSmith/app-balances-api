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
        // Solo la regla de repetición. Los datos (monto, descripción...) salen del
        // movimiento base; movimiento_base_id se agrega al crear movimientos (se referencian entre sí).
        Schema::create('programaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('intervalo')->default(1); // cada N...
            $table->enum('frecuencia', ['dias', 'semanas', 'meses', 'anios']); // ...días/semanas/meses/años
            $table->date('fecha_inicio'); // ancla del calendario
            $table->date('fecha_fin')->nullable();
            $table->unsignedInteger('ocurrencias')->default(0); // fechas ya consumidas desde fecha_inicio
            $table->date('proxima_fecha');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['activo', 'proxima_fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programaciones');
    }
};
