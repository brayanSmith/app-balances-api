<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Es solo una API: sin token se responde 401 en vez de redirigir a un login web.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Laravel devuelve estos mensajes en inglés sin pasarlos por la traducción
        // ("Unauthenticated.", "Too Many Attempts."…) y la app los muestra tal cual.
        $json = fn (Request $request, string $mensaje, int $estado, array $headers = []) => $request->is('api/*')
            ? response()->json(['message' => $mensaje], $estado, $headers)
            : null;

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $json(
            $request, 'Tu sesión venció o no has iniciado sesión. Vuelve a entrar.', 401,
        ));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $json(
            $request, 'Demasiados intentos. Espera un momento y vuelve a intentarlo.', 429, $e->getHeaders(),
        ));

        $exceptions->render(fn (InvalidSignatureException $e, Request $request) => $json(
            $request, 'El enlace venció. Vuelve a abrir el comprobante desde la app.', 403,
        ));

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $json(
            $request, 'No tienes permiso para hacer esto.', 403,
        ));

        // Incluye un registro que no existe o es de otro usuario ("No query results for model…").
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $json(
            $request, 'No se encontró lo que buscas. Puede que se haya eliminado.', 404,
        ));

        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $json(
            $request, 'El archivo es demasiado grande para subirlo.', 413,
        ));
    })->create();
