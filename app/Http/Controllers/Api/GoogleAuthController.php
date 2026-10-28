<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * "Continuar con Google": la app manda el ID token que le dio Google; se verifica
 * con Google (firma, vencimiento y que sea para esta app) y se entra o se crea la
 * cuenta. Devuelve { user, token } igual que el login.
 */
class GoogleAuthController extends Controller
{
    private const VERIFICAR = 'https://oauth2.googleapis.com/tokeninfo';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['id_token' => ['required', 'string', 'max:4096']]);

        $google = $this->verificar($request->input('id_token'));

        $user = User::where('google_id', $google['sub'])->first();
        $nuevo = false;

        if (! $user) {
            // Ya tenía cuenta con ese correo: se vincula (Google confirmó que el correo es suyo).
            $user = User::where('email', $google['email'])->first();

            if ($user) {
                $user->forceFill([
                    'google_id' => $google['sub'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            } else {
                $user = DB::transaction(function () use ($google) {
                    $nombre = $google['name'] ?? strstr($google['email'], '@', true);
                    $tenant = Tenant::create(['nombre' => $nombre]);

                    return $tenant->users()->forceCreate([
                        'name' => $nombre,
                        'email' => $google['email'],
                        'google_id' => $google['sub'],
                        'email_verified_at' => now(),
                    ]);
                });
                $nuevo = true;
            }
        }

        return response()->json([
            'user' => $user->load('tenant'),
            'token' => $user->createToken('mobile')->plainTextToken,
        ], $nuevo ? 201 : 200);
    }

    /**
     * Pregunta a Google si el token es válido. Además de la firma y el vencimiento
     * (que revisa Google), se exige que sea para uno de nuestros clientes (aud):
     * un token de Google emitido para otra app no sirve aquí.
     *
     * @return array{sub: string, email: string, name?: string}
     */
    private function verificar(string $idToken): array
    {
        $invalido = fn () => ValidationException::withMessages([
            'id_token' => ['No se pudo verificar tu cuenta de Google. Inténtalo de nuevo.'],
        ]);

        $respuesta = Http::timeout(10)->get(self::VERIFICAR, ['id_token' => $idToken]);
        $datos = $respuesta->json() ?? [];

        $clientes = array_filter(explode(',', (string) config('services.google.client_ids')));
        $valido = $respuesta->ok()
            && in_array($datos['aud'] ?? null, $clientes, true)
            && in_array($datos['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($datos['exp'] ?? 0) > now()->getTimestamp()
            && ! empty($datos['sub'])
            && ! empty($datos['email']);

        if (! $valido) {
            throw $invalido();
        }
        if (($datos['email_verified'] ?? null) !== 'true' && ($datos['email_verified'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'id_token' => ['Tu cuenta de Google no tiene el correo verificado.'],
            ]);
        }

        return $datos;
    }
}
