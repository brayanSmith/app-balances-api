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
        // Registro de cada CSV importado, para poder deshacerlo.
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('archivo');
            $table->unsignedInteger('filas'); // filas de datos del CSV
            $table->unsignedInteger('importados');
            $table->unsignedInteger('duplicados'); // ya existían y se omitieron
            $table->unsignedInteger('omitidos'); // con error, si se eligió omitirlos
            $table->timestamps();
        });

        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('importacion_id')->nullable()->after('programacion_id')
                ->constrained('importaciones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('importacion_id');
        });

        Schema::dropIfExists('importaciones');
    }
};
