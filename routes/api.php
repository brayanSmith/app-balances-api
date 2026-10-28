<?php

use App\Http\Controllers\Api\AdjuntoController;
use App\Http\Controllers\Api\AnalisisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\CuentaController;
use App\Http\Controllers\Api\ImportacionController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\PerfilController;
use App\Http\Controllers\Api\ProgramacionController;
use App\Http\Controllers\Api\RecuperarPasswordController;
use Illuminate\Support\Facades\Route;

// La app la consulta para saber si hay conexión con el servidor (modo sin conexión).
Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Recuperar la contraseña con un código enviado al correo.
Route::post('/password/codigo', [RecuperarPasswordController::class, 'enviarCodigo'])->middleware('throttle:5,1');
Route::post('/password/restablecer', [RecuperarPasswordController::class, 'restablecer'])->middleware('throttle:10,1');

// Ver un adjunto: sin token, protegido por el enlace firmado y temporal.
Route::get('/adjuntos/{adjunto}/archivo', [AdjuntoController::class, 'archivo'])
    ->middleware('signed')
    ->name('adjuntos.archivo');

// Foto de perfil: igual, con enlace firmado (la usa <Image> en la app).
Route::get('/perfil/foto/{user}', [PerfilController::class, 'foto'])
    ->middleware('signed')
    ->name('perfil.foto');

// CSV de ejemplo para importar (no tiene datos del usuario).
Route::get('/importaciones/plantilla', [ImportacionController::class, 'plantilla']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/cuenta/restablecer', [CuentaController::class, 'restablecer'])->middleware('throttle:5,1');

    Route::put('/perfil', [PerfilController::class, 'update']);
    Route::put('/perfil/password', [PerfilController::class, 'password'])->middleware('throttle:5,1');
    Route::post('/perfil/foto', [PerfilController::class, 'subirFoto']);
    Route::delete('/perfil/foto', [PerfilController::class, 'quitarFoto']);

    Route::get('/analisis', AnalisisController::class);

    Route::get('/movimientos/resumen', [MovimientoController::class, 'resumen']);
    Route::apiResource('movimientos', MovimientoController::class);
    Route::post('/movimientos/{movimiento}/adjuntos', [AdjuntoController::class, 'store']);
    Route::delete('/adjuntos/{adjunto}', [AdjuntoController::class, 'destroy']);

    Route::get('/programaciones/pendientes', [ProgramacionController::class, 'pendientes']);
    Route::post('/programaciones/{programacion}/pagar', [ProgramacionController::class, 'pagar']);
    Route::post('/programaciones/{programacion}/omitir', [ProgramacionController::class, 'omitir']);

    Route::apiResource('categorias', CategoriaController::class);

    Route::get('/importaciones', [ImportacionController::class, 'index']);
    Route::post('/importaciones/comprimidos', [ImportacionController::class, 'subirComprimido']);
    Route::post('/importaciones/previsualizar', [ImportacionController::class, 'previsualizar']);
    Route::post('/importaciones', [ImportacionController::class, 'store'])->name('importaciones.store');
    Route::delete('/importaciones/{importacion}', [ImportacionController::class, 'destroy']);
});
