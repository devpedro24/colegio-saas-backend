<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Complementa snapshots de dominio con lecturas, accesos denegados y resultados HTTP.
 * Nunca registra cuerpos, query strings ni cabeceras de autenticación.
 */
class AuditRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->is('api/*') || $request->is('api/tenant-status', 'api/broadcasting/auth')) {
            return $response;
        }
        $actor = $request->user();
        if ($actor !== null || $request->is('api/login', 'api/forgot-password', 'api/reset-password')) {
            $method = tenancy()->initialized ? 'tenant' : 'platform';
            $action = $response->getStatusCode() >= 400 ? 'REQUEST_DENIED' : ($request->isMethod('GET') ? 'READ' : 'REQUEST');
            AuditLogger::$method($actor, $action, 'http', null, null, [
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'status' => $response->getStatusCode(),
            ]);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
