<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain as DomainMiddleware;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain as BaseMiddleware;

/**
 * Resolucion de tenancy por dominio o subdominio, con soporte de sedes
 * (tenants hijos): `sede.colegio-x.localhost` resuelve `sede.colegio-x`.
 *
 * Solo se reemplaza el middleware de subdominios; el de dominio completo
 * (hosts de terceros / centrales) queda igual.
 */
class InitializeTenancyByDomainOrSubdomain extends BaseMiddleware
{
    /**
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Str::endsWith($request->getHost(), config('tenancy.central_domains'))) {
            return app(InitializeTenancyBySubdomain::class)->handle($request, $next);
        }

        return app(DomainMiddleware::class)->handle($request, $next);
    }
}
