@extends('legal.layout')

@section('titulo', 'Eliminar tu cuenta')

@section('contenido')
@if (session('eliminada'))
    <div class="tarjeta exito">
        <strong>Tu cuenta fue eliminada.</strong> Se borraron tus datos: movimientos, programados, categorías,
        comprobantes, foto e importaciones. Si tenías la app abierta en un teléfono, la sesión se cerrará sola.
    </div>
@else
    <div class="tarjeta">
        <h2>Desde la app (lo más fácil)</h2>
        <p>Abre {{ config('app.name') }} y ve a <strong>Mi perfil → Eliminar cuenta</strong>.</p>

        <h2>Desde aquí</h2>
        <p>Escribe el correo y la contraseña de tu cuenta.</p>

        <div class="aviso">
            <strong>Esto no se puede deshacer.</strong> Se borran de inmediato tu cuenta y todos sus datos:
            movimientos, pagos programados, categorías, comprobantes (fotos y PDF), foto de perfil e importaciones.
        </div>

        <form method="POST" action="{{ route('eliminar-cuenta') }}">
            @csrf
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            @foreach ($errors->all() as $error)
                <p class="error">{{ $error }}</p>
            @endforeach

            <label class="casilla">
                <input type="checkbox" name="confirmo" value="1" required>
                <span>Entiendo que mi cuenta y todos mis datos se borrarán para siempre.</span>
            </label>

            <button type="submit">Eliminar mi cuenta</button>
        </form>

        <p class="suave" style="margin-top: 16px;">
            ¿Entraste con Google y no tienes contraseña? Elimínala desde la app, o crea una contraseña con
            «¿Olvidaste tu contraseña?» y vuelve aquí.
            @if (config('app.contacto'))
                Si no puedes entrar de ninguna forma, escríbenos a
                <a href="mailto:{{ config('app.contacto') }}">{{ config('app.contacto') }}</a> desde el correo de tu cuenta.
            @endif
        </p>
        <p class="suave">Más información en la <a href="{{ route('privacidad') }}">política de privacidad</a>.</p>
    </div>
@endif
@endsection
