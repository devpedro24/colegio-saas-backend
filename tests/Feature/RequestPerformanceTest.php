<?php

namespace Tests\Feature;

use App\Http\Middleware\MeasureRequestPerformance;
use App\Support\RequestPerformance;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RequestPerformanceTest extends TestCase
{
    public function test_metrics_keep_only_route_template_and_aggregate_times(): void
    {
        config(['performance.enabled' => true, 'performance.slow_ms' => 0]);
        $request = Request::create('/api/users/private-token?email=private@example.test', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'api/users/{user}', fn () => null));
        Log::shouldReceive('info')->once()->withArgs(function ($message, $fields) {
            return $message === 'http.performance' && $fields['route'] === 'api/users/{user}'
                && $fields['status'] === 200 && $fields['db_queries'] === 1 && $fields['db_ms'] === 12.5
                && ! str_contains(json_encode($fields), 'private');
        });
        app(MeasureRequestPerformance::class)->handle($request, function () {
            app(RequestPerformance::class)->record(12.5);
            return response()->json(['ok' => true]);
        });
        $this->assertFalse(app(RequestPerformance::class)->active);
        app(RequestPerformance::class)->record(100);
        $this->assertSame(1, app(RequestPerformance::class)->queries);
    }

    public function test_disabled_metrics_do_not_log(): void
    {
        config(['performance.enabled' => false]);
        Log::shouldReceive('info')->never();
        $response = app(MeasureRequestPerformance::class)->handle(Request::create('/api/me'), fn () => response()->json([]));
        $this->assertSame(200, $response->getStatusCode());
    }
}
