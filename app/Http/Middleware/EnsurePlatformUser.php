<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite el acceso solo a usuarios de PLATAFORMA (superadministrador).
 * Se usa en las rutas centrales del panel del superadmin.
 */
class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'superadmin') {
            abort(403, 'Solo el superadministrador de la plataforma puede acceder.');
        }

        return $next($request);
    }
}
