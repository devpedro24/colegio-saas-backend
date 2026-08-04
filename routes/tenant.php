<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\MfaController;
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

    // Callback de Google (anclar cuenta): PUBLICO (lo llama Google tras el
    // consentimiento). No puede ir bajo auth:sanctum.
    Route::get('/account/google/callback', [AccountController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Autorizacion de canales privados (WebSockets) del colegio (tenant actual).
        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

        // MFA TOTP (segundo factor) del usuario del colegio.
        Route::post('/mfa/setup', [MfaController::class, 'setup']);
        Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('/mfa/disable', [MfaController::class, 'disable']);

        // Ajustes de cuenta del usuario autenticado (perfil, email, clave,
        // desactivacion y vinculacion de Google).
        Route::get('/account', [AccountController::class, 'index']);
        Route::put('/account/profile', [AccountController::class, 'updateProfile']);
        Route::post('/account/email', [AccountController::class, 'changeEmail']);
        Route::post('/account/password', [AccountController::class, 'changePassword']);
        Route::post('/account/deactivate', [AccountController::class, 'deactivate']);
        Route::post('/account/google/connect', [AccountController::class, 'googleConnect']);
        Route::delete('/account/google', [AccountController::class, 'googleUnlink']);

        // RBAC: solo quien tenga el permiso 'usuarios.ajustar_permisos' (el rector).
        Route::middleware('can:usuarios.ajustar_permisos')->group(function () {
            Route::get('/rbac/roles', [RoleController::class, 'index']);
            Route::put('/rbac/roles/{role}/permissions/{permission}', [RoleController::class, 'togglePermission']);
        });

        /*
         | Académico — Bloque A (Fase 1): años lectivos, periodos y configuración
         | del colegio. Se definen en routes/tenant_academico.php (compartidas con
         | el grupo central header-resuelto de la suplantación, en routes/api.php)
         | para que ambos caminos apunten a los MISMOS controladores del colegio.
         | Aquí ya estamos bajo `api` + `auth:sanctum` + tenancy por subdominio.
         */
        require base_path('routes/tenant_academico.php');
    });
});
