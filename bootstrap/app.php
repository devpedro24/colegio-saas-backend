<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
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
        // Solo el superadministrador de plataforma (rutas centrales del panel).
        $middleware->alias([
            'platform' => \App\Http\Middleware\EnsurePlatformUser::class,
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
