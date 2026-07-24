<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a un colegio (tenant) INHABILITADO.
 *
 * Se ejecuta DESPUES de inicializar el tenant por subdominio. Si el colegio esta
 * suspendido (o en un estado terminal), toda la app del colegio queda inaccesible
 * (login incluido): el superadmin puede habilitarlo/inhabilitarlo desde el panel.
 */
class EnsureTenantActive
{
    /** Estados que dejan al colegio inaccesible. */
    private const BLOCKED = [
        Tenant::STATUS_SUSPENDED,
        Tenant::STATUS_CANCELLATION_REQUESTED,
        Tenant::STATUS_FINALIZED,
        Tenant::STATUS_IN_RETENTION,
        Tenant::STATUS_DELETED,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant && in_array($tenant->status, self::BLOCKED, true)) {
            abort(403, 'Este colegio esta inhabilitado. Contacta al administrador de la plataforma.');
        }

        return $next($request);
    }
}
