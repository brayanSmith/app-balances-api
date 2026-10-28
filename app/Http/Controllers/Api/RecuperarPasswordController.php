<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\CodigoRecuperacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * "¿Olvidaste tu contraseña?": se envía un código de 6 dígitos al correo y con él
 * se elige una contraseña nueva sin salir de la app.
 */
class RecuperarPasswordController extends Controller
{
    public const MINUTOS_VIGENCIA = 15;

    public const INTENTOS = 5;

    /** Mínimo entre dos correos a la misma dirección. */
    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    public function enviarCodigo(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'email']])['email'];

        $user = User::where('email', $email)->first();
        $anterior = DB::table('password_reset_tokens')->where('email', $email)->first();
        $reciente = $anterior && now()->subSeconds(self::SEGUNDOS_ENTRE_ENVIOS)->lt($anterior->created_at);

        if ($user && ! $reciente) {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($codigo), 'created_at' => now()],
            );
            RateLimiter::clear($this->llaveIntentos($email));

            // Después de responder: la app no espera al servidor de correo (puede tardar varios segundos).
            defer(fn () => $user->notify(new CodigoRecuperacion($codigo)));
        }

        // La misma respuesta exista o no la cuenta: así no se puede averiguar quién está registrado.
        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un código para restablecer tu contraseña.',
        ]);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'codigo' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $email = $data['email'];
        $llave = $this->llaveIntentos($email);

        $registro = DB::table('password_reset_tokens')->where('email', $email)->first();
        $vigente = $registro && now()->subMinutes(self::MINUTOS_VIGENCIA)->lt($registro->created_at);

        if (! $vigente || RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
            throw ValidationException::withMessages([
                'codigo' => ['El código venció o ya no es válido. Pide uno nuevo.'],
            ]);
        }

        if (! Hash::check($data['codigo'], $registro->token)) {
            RateLimiter::hit($llave, self::MINUTOS_VIGENCIA * 60);

            // Tras varios intentos fallidos el código deja de servir (evita adivinarlo).
            if (RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                throw ValidationException::withMessages([
                    'codigo' => ['Demasiados intentos. Pide un código nuevo.'],
                ]);
            }

            throw ValidationException::withMessages(['codigo' => ['El código no es correcto.']]);
        }

        $user = User::where('email', $email)->firstOrFail();

        $token = DB::transaction(function () use ($user, $data, $email) {
            // El código llegó a su correo: queda verificado.
            $user->forceFill([
                'password' => $data['password'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // Quien tuviera la contraseña anterior pierde el acceso en todos los dispositivos.
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return $user->createToken('mobile')->plainTextToken;
        });
        RateLimiter::clear($llave);

        return response()->json(['user' => $user->load('tenant'), 'token' => $token]);
    }

    private function llaveIntentos(string $email): string
    {
        return 'recuperar-password:'.$email;
    }
}
