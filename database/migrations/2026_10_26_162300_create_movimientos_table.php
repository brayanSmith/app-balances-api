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
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            // Id que genera la app para lo creado sin conexión: si el envío se repite
            // (se cayó la señal justo después de guardar), no se duplica.
            $table->uuid('cliente_id')->nullable();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programacion_id')->nullable()->constrained('programaciones')->nullOnDelete();
            $table->foreignId('importacion_id')->nullable()->constrained('importaciones')->nullOnDelete();
            $table->enum('tipo', ['ingreso', 'gasto']);
            $table->decimal('monto', 12, 2);
            $table->string('descripcion');
            $table->foreignId('categoria_id')->nullable()->constrained()->nullOnDelete();
            $table->date('fecha');
            // Vencimiento que cubre un pago de un programado (la "fecha" es cuándo se pagó).
            $table->date('fecha_programada')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
            $table->unique(['tenant_id', 'cliente_id']);
        });

        // programaciones y movimientos se referencian entre sí: esta llave va cuando ya existen las dos.
        Schema::table('programaciones', function (Blueprint $table) {
            $table->foreignId('movimiento_base_id')->nullable()->after('tenant_id')
                ->constrained('movimientos')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movimiento_base_id');
        });

        Schema::dropIfExists('movimientos');
    }
};
