<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Academico\AnoLectivo;
use App\Support\OpaqueUrlToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resolve the tenant-bound public year selector before the SIEE controller runs. */
final class ResolveOpaqueSieeYear
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $token = $route?->parameter('id');

        // Numeric database IDs and the old long selector are never valid here.
        abort_unless(OpaqueUrlToken::valid($token), 404);

        // Years are a small tenant-scoped catalog. Never resolve against the
        // central connection or use a client-provided database identifier.
        $year = OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query());
        abort_unless($year, 404);
        $route->setParameter('id', $year->id);

        return $next($request);
    }
}
