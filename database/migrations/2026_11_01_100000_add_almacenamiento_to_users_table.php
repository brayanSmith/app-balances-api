<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dónde guarda la cuenta sus datos (lo elige el administrador en /admin):
        // - nube: en el teléfono y sincronizados con la API (MySQL).
        // - local: solo en el teléfono; la API solo inicia sesión.
        Schema::table('users', function (Blueprint $table) {
            $table->enum('almacenamiento', ['nube', 'local'])->default('nube')->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('almacenamiento');
        });
    }
};
