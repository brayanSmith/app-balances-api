<?php

use App\Http\Controllers\Admin\InicioController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

// Panel de administración (Inertia + React), solo para el super admin.
Route::middleware(['auth', 'super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', InicioController::class)->name('inicio');
    Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::patch('usuarios/{user}/almacenamiento', [UsuarioController::class, 'almacenamiento'])
        ->name('usuarios.almacenamiento');
});

// Páginas públicas que pide Google Play (sus URLs van en la ficha de la app).
Route::get('/privacidad', [LegalController::class, 'privacidad'])->name('privacidad');
Route::get('/eliminar-cuenta', [LegalController::class, 'eliminarCuenta'])->name('eliminar-cuenta');
Route::post('/eliminar-cuenta', [LegalController::class, 'confirmarEliminacion'])->middleware('throttle:5,1');

require __DIR__.'/settings.php';
