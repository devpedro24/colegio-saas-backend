<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Realtime\RealtimeChanges;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SynchronizeRealtimeChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $changes = app(RealtimeChanges::class);
        $changes->begin();
        try {
            $response = $next($request);
            // Covers pivot sync, query-builder bulk writes and future API actions too.
            if ($request->is('api/*') && ! $request->isMethodSafe()
                && $response->getStatusCode() < 400 && $request->user()
                && ! $request->is('api/broadcasting/auth', 'api/tenant-broadcasting/auth', 'api/login', 'api/logout', 'api/forgot-password')) {
                $changes->record(tenant()?->getKey(), RealtimeChanges::resourceForPath($request->path()));
            }

            return $response;
        } finally {
            // Only committed callbacks enter the buffer, including partial commits on errors.
            $changes->flush();
        }
    }
}
