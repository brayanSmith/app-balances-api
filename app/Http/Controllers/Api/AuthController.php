<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'tenant_nombre' => ['nullable', 'string', 'max:255'],
        ]);

        // Cada registro crea su propio tenant y el usuario queda dentro de él.
        $user = DB::transaction(fn () => Tenant::abrirCuenta(
            $data['tenant_nombre'] ?? $data['name'],
            fn (Tenant $tenant) => $tenant->users()->create(Arr::except($data, 'tenant_nombre')),
        ));

        return response()->json([
            'user' => $user->load('tenant'),
            'token' => $user->createToken('mobile')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->password === null) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta entra con Google. Usa «Continuar con Google» o crea una contraseña con «¿Olvidaste tu contraseña?».'],
            ]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Correo o contraseña incorrectos.'],
            ]);
        }

        return response()->json([
            'user' => $user->load('tenant'),
            'token' => $user->createToken('mobile')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('tenant'));
    }
}
