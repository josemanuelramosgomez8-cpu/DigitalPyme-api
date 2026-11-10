<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->is_client && $user->client()->exists(), 403, 'No tienes permiso para realizar esta acción.');
        abort_unless($user->is_active, 403, 'La cuenta está desactivada. Contacta con el administrador.');

        if ($user->must_change_password && !$request->routeIs('client.password')) {
            abort(423, 'Debes cambiar tu contraseña antes de continuar.');
        }

        return $next($request);
    }
}
