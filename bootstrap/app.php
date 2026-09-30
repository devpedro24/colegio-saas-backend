<?php

use App\Http\Middleware\AuditRequests;
use App\Http\Middleware\EnsurePlatformUser;
use App\Http\Middleware\ResolveBrowserSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API clients without an Accept header must receive JSON 401, not a
        // redirect to Laravel's nonexistent web login route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/auth');
        $middleware->prepend(ResolveBrowserSession::class);
        $middleware->prepend(\App\Http\Middleware\MeasureRequestPerformance::class);
        $middleware->append(\App\Http\Middleware\SynchronizeRealtimeChanges::class);
        $middleware->append(AuditRequests::class);
        // Solo el superadministrador de plataforma (rutas centrales del panel).
        $middleware->alias([
            'platform' => EnsurePlatformUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API sin vista 'login': el superadmin NO autenticado responde JSON 401
        // (en vez del 500 que produce route('login') al no existir).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        });

        // Un subdominio que no corresponde a ningun colegio -> 404 limpio,
        // sin filtrar detalles internos (en vez de un 500).
        $exceptions->render(function (TenantCouldNotBeIdentifiedException $e, Request $request) {
            return response()->json([
                'error' => 'colegio_no_encontrado',
                'mensaje' => 'El subdominio no corresponde a ningun colegio registrado.',
            ], 404);
        });
    })->create();
