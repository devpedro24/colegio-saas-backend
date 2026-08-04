<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Exception;
use Illuminate\Support\Str;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain as BaseMiddleware;

/**
 * Identificacion por subdominio con soporte de sedes (tenants hijos).
 *
 * El middleware de Stancl devuelve `parts[0]` del host (solo funciona con
 * subdominios de un segmento: `colegio-x.localhost`). Las sedas viven en
 * subdominios anidados (`sede.colegio-x.localhost`), por lo que el dominio a
 * resolver es el host COMPLETO sin el dominio central (`sede.colegio-x`).
 *
 * El resto del flujo (excepciones, resolución exacta en la tabla `domains`)
 * se delega en la implementacion de Stancl.
 */
class InitializeTenancyBySubdomain extends BaseMiddleware
{
    /** @return string|Exception */
    protected function makeSubdomain(string $hostname)
    {
        $parts = explode('.', $hostname);

        $isLocalhost = count($parts) === 1;
        $isIpAddress = count(array_filter($parts, 'is_numeric')) === count($parts);
        $isACentralDomain = in_array($hostname, config('tenancy.central_domains'), true);
        $thirdPartyDomain = ! Str::endsWith($hostname, config('tenancy.central_domains'));

        if ($isACentralDomain || $isLocalhost || $isIpAddress || $thirdPartyDomain) {
            return new NotASubdomainException($hostname);
        }

        $domain = $hostname;

        foreach (config('tenancy.central_domains') as $centralDomain) {
            if (Str::endsWith($hostname, '.'.$centralDomain)) {
                $domain = rtrim(Str::beforeLast($hostname, $centralDomain), '.');
                break;
            }
        }

        if ($domain === '') {
            return new NotASubdomainException($hostname);
        }

        return $domain;
    }
}
