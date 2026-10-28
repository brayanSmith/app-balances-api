<?php

use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Páginas públicas que pide Google Play (sus URLs van en la ficha de la app).
Route::get('/privacidad', [LegalController::class, 'privacidad'])->name('privacidad');
Route::get('/eliminar-cuenta', [LegalController::class, 'eliminarCuenta'])->name('eliminar-cuenta');
Route::post('/eliminar-cuenta', [LegalController::class, 'confirmarEliminacion'])->middleware('throttle:5,1');
