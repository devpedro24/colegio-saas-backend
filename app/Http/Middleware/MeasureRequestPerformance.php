<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestPerformance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class MeasureRequestPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.enabled') || ! $request->is('api/*')) {
            return $next($request);
        }
        $counter = app(RequestPerformance::class);
        $counter->begin();
        $start = hrtime(true);
        $response = null;
        try {
            return $response = $next($request);
        } finally {
            $counter->active = false;
            $duration = (hrtime(true) - $start) / 1e6;
            $rate = max(0, min(1, (float) config('performance.sample_rate')));
            if ($duration >= (float) config('performance.slow_ms') || ($rate > 0 && mt_rand() / mt_getrandmax() < $rate)) {
                Log::info('http.performance', [
                    'method' => $request->method(), 'route' => $request->route()?->uri() ?? '(unmatched)',
                    'status' => $response?->getStatusCode() ?? 500,
                    'duration_ms' => round($duration, 2), 'db_ms' => round($counter->databaseMs, 2),
                    'db_queries' => $counter->queries,
                ]);
            }
        }
    }
}
