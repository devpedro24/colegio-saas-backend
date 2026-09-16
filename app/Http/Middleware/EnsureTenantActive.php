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
    /** Estados terminales que dejan al colegio totalmente inaccesible. */
    private const BLOCKED = [
        Tenant::STATUS_CANCELLATION_REQUESTED,
        Tenant::STATUS_FINALIZED,
        Tenant::STATUS_IN_RETENTION,
        Tenant::STATUS_DELETED,
    ];

    /** Consultas academicas esenciales permitidas durante suspension. */
    private const SUSPENDED_READ_PREFIXES = [
        'api/notas',
        'api/asistencia',
        'api/observador',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant && in_array($tenant->status, self::BLOCKED, true)) {
            abort(403, 'Este colegio esta inhabilitado. Contacta al administrador de la plataforma.');
        }

        if ($tenant?->status === Tenant::STATUS_SUSPENDED && ! self::allowsSuspendedRequest($request)) {
            abort(403, 'El colegio esta suspendido. Solo estan disponibles las consultas academicas esenciales.');
        }

        return $next($request);
    }

    public static function allowsSuspendedRequest(Request $request): bool
    {
        if ($request->isMethod('GET') && in_array($request->path(), ['api/me', 'api/tenant-status'], true)) {
            return true;
        }

        if ($request->isMethod('POST') && in_array($request->path(), ['api/login', 'api/logout'], true)) {
            return true;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        foreach (self::SUSPENDED_READ_PREFIXES as $prefix) {
            if ($request->is($prefix) || $request->is($prefix.'/*')) {
                return true;
            }
        }

        return false;
    }
}
