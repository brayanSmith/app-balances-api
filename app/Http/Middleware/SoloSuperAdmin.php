<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel web es solo del super admin. El login ya no deja entrar a nadie más; esto
 * cubre una sesión que siga abierta si se le quita el permiso.
 */
class SoloSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->esSuperAdmin(), 403);

        return $next($request);
    }
}
