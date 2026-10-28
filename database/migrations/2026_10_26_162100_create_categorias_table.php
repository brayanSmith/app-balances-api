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
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('tipo', ['ingreso', 'gasto']);
            $table->string('nombre', 50);
            $table->string('icono', 50); // nombre de un ícono de Ionicons
            $table->string('color', 7); // #RRGGBB
            $table->timestamps();

            $table->unique(['tenant_id', 'tipo', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
