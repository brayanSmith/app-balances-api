<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EliminadorDeCuenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Páginas públicas que exige Google Play: la política de privacidad y una web
 * para eliminar la cuenta sin tener la app instalada.
 */
class LegalController extends Controller
{
    public function privacidad(): View
    {
        return view('legal.privacidad');
    }

    public function eliminarCuenta(): View
    {
        return view('legal.eliminar-cuenta');
    }

    public function confirmarEliminacion(Request $request, EliminadorDeCuenta $eliminador): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'confirmo' => ['accepted'],
        ], [
            'confirmo.accepted' => 'Marca la casilla para confirmar que entiendes que no se puede deshacer.',
        ]);

        $user = User::where('email', $datos['email'])->first();

        if ($user && $user->password === null) {
            throw ValidationException::withMessages(['email' => [
                'Esta cuenta entra con Google y no tiene contraseña. Elimínala desde la app (Mi perfil → Eliminar cuenta) '
                .'o crea una contraseña con «¿Olvidaste tu contraseña?» y vuelve aquí.',
            ]]);
        }
        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Correo o contraseña incorrectos.']]);
        }

        $eliminador->eliminar($user);

        return redirect()->route('eliminar-cuenta')->with('eliminada', true);
    }
}
