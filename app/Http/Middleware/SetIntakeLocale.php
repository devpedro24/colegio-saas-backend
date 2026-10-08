<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetIntakeLocale
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/ingreso/*', 'api/ingreso-publico/*', 'api/correo-institucional', 'api/correo-institucional/*', 'api/platform/correo-solicitudes', 'api/platform/correo-solicitudes/*')) {
            return $next($request);
        }
        $previous = app()->getLocale();
        $locale = $request->getPreferredLanguage(['es', 'en']) ?? 'es';
        app()->setLocale($locale);
        try {
            return $next($request);
        } finally {
            // Do not leak a browser preference to the next request in long-lived workers.
            app()->setLocale($previous);
        }
    }
}
