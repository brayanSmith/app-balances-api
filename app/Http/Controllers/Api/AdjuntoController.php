<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdjuntoResource;
use App\Models\Adjunto;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdjuntoController extends Controller
{
    public function store(Request $request, Movimiento $movimiento): AdjuntoResource
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
        ], [
            'archivo.max' => 'El archivo no puede pesar más de 10 MB.',
            'archivo.mimes' => 'Solo se permiten imágenes (JPG, PNG, WEBP, HEIC) o PDF.',
        ]);

        $archivo = $request->file('archivo');

        $adjunto = $movimiento->adjuntos()->create([
            'nombre' => $archivo->getClientOriginalName(),
            'ruta' => $archivo->store("adjuntos/{$movimiento->tenant_id}"),
            'mime' => $archivo->getMimeType(),
            'tamano' => $archivo->getSize(),
        ]);

        return new AdjuntoResource($adjunto);
    }

    public function destroy(Adjunto $adjunto): Response
    {
        $adjunto->delete();

        return response()->noContent();
    }

    /**
     * Sirve el archivo. Va fuera de auth:sanctum: lo protege la firma del enlace.
     */
    public function archivo(Adjunto $adjunto): StreamedResponse
    {
        return Storage::disk()->response($adjunto->ruta, $adjunto->nombre, [
            'Content-Type' => $adjunto->mime,
            'Cache-Control' => 'private, max-age=1800',
        ], 'inline');
    }
}
