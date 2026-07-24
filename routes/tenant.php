<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Middleware\EnsureTenantActive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Rutas del Tenant (colegio)
|--------------------------------------------------------------------------
|
| Se resuelven por subdominio: <slug>.<dominio-central>. La identidad del
| tenant se infiere del subdominio ANTES de tocar la BD del colegio
| (RN-AU-001). PreventAccessFromCentralDomains impide que estas rutas
| respondan en el dominio central de la plataforma.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomainOrSubdomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    // Endpoint de diagnostico: confirma en que tenant estamos y con que BD.
    Route::get('/', function () {
        /** @var \App\Models\Tenant $tenant */
        $tenant = tenant();

        return response()->json([
            'contexto' => 'tenant',
            'colegio' => $tenant->name,
            'slug' => $tenant->slug,
            'tenant_id' => $tenant->id,
            'plan' => $tenant->plan,
            'estado' => $tenant->status,
            'base_de_datos' => $tenant->database()->getName(),
            'usuarios' => \App\Models\User::count(),
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| API del Tenant (stateless, token Sanctum)
|--------------------------------------------------------------------------
|
| Se resuelve el tenant por subdominio ANTES de autenticar, para que el token
| se busque en la BD del colegio. Todo bajo /api.
|
*/
Route::middleware([
    'api',
    InitializeTenancyByDomainOrSubdomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantActive::class,
])->prefix('api')->group(function () {
    // Login del colegio (rector, coordinador, etc.).
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Autorizacion de canales privados (WebSockets) del colegio (tenant actual).
        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

        // RBAC: solo quien tenga el permiso 'usuarios.ajustar_permisos' (el rector).
        Route::middleware('can:usuarios.ajustar_permisos')->group(function () {
            Route::get('/rbac/roles', [RoleController::class, 'index']);
            Route::put('/rbac/roles/{role}/permissions/{permission}', [RoleController::class, 'togglePermission']);
        });
    });
});
