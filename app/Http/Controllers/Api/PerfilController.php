<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Mi perfil": nombre, correo, contraseña y foto del usuario con sesión iniciada.
 * Todas devuelven el usuario actualizado, igual que GET /user.
 */
class PerfilController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            // Cambiar el correo cambia con qué se entra: se pide la contraseña.
            'password_actual' => [
                Rule::requiredIf($request->input('email') !== $user->email),
                'nullable',
                'current_password:sanctum',
            ],
        ], [
            'email.unique' => 'Ese correo ya lo usa otra cuenta.',
            'password_actual.required' => 'Escribe tu contraseña actual para cambiar el correo.',
            'password_actual.current_password' => 'La contraseña no es correcta.',
        ]);

        $user->name = $data['name'];
        if ($data['email'] !== $user->email) {
            $user->email = $data['email'];
            $user->email_verified_at = null;
        }
        $user->save();

        return response()->json($user->load('tenant'));
    }

    public function password(Request $request): JsonResponse
    {
        $request->validate([
            'password_actual' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:password_actual'],
        ], [
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.confirmed' => 'Las contraseñas nuevas no coinciden.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        $user = $request->user();
        $user->update(['password' => $request->input('password')]);

        // Se cierra la sesión en los demás dispositivos; en este se sigue dentro.
        $actual = $user->currentAccessToken()?->id ?? null;
        $user->tokens()->when($actual, fn ($q) => $q->whereKeyNot($actual))->delete();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    public function subirFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:5120'],
        ], [
            'foto.image' => 'La foto debe ser una imagen.',
            'foto.mimes' => 'Solo se permiten imágenes JPG, PNG, WEBP o HEIC.',
            'foto.max' => 'La foto no puede pesar más de 5 MB.',
        ]);

        $user = $request->user();
        $anterior = $user->foto;

        $user->forceFill(['foto' => $request->file('foto')->store("fotos/{$user->id}", 'local')])->save();
        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }

        return response()->json($user->load('tenant'));
    }

    public function quitarFoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->foto) {
            Storage::disk('local')->delete($user->foto);
            $user->forceFill(['foto' => null])->save();
        }

        return response()->json($user->load('tenant'));
    }

    /**
     * Sirve la foto. Va fuera de auth:sanctum: lo protege la firma del enlace.
     */
    public function foto(User $user): StreamedResponse
    {
        abort_unless($user->foto && Storage::disk('local')->exists($user->foto), 404);

        return Storage::disk('local')->response($user->foto, null, [
            'Cache-Control' => 'private, max-age=604800',
        ], 'inline');
    }
}
