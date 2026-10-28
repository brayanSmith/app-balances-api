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
        // Comprobantes de un movimiento (fotos de facturas, PDF...).
        Schema::create('adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movimiento_id')->constrained()->cascadeOnDelete();
            $table->string('nombre'); // nombre original del archivo
            $table->string('ruta'); // en el disco "local" (privado)
            $table->string('mime', 100);
            $table->unsignedInteger('tamano'); // bytes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjuntos');
    }
};
