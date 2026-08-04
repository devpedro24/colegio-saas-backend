<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Api\Platform\ColegioController;
use App\Http\Controllers\Api\Platform\ColegioSedeController;
use App\Http\Controllers\Api\Platform\ImpersonationController;
use App\Http\Controllers\Api\Platform\MfaController as PlatformMfaController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Controllers\Api\Platform\RbacController;
use App\Http\Controllers\Api\Platform\StorageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| API CENTRAL (plataforma / superadministrador)
|--------------------------------------------------------------------------
|
| Se restringe a los dominios centrales (localhost, 127.0.0.1) para NO chocar
| con las rutas de colegio (routes/tenant.php), que se resuelven por subdominio.
| En el dominio central se autentica al superadministrador contra la BD central.
|
*/
foreach (config('tenancy.central_domains') as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::post('/login', [PlatformAuthController::class, 'login']);

        // Descarga de archivos del colegio con URL FIRMADA de corta vida
        // (RN-AC-004): la firma incluye el tenant dueno para impedir cruces.
        Route::get('/storage/{file}/download', [StorageController::class, 'download'])
            ->middleware('signed')
            ->name('storage.file');

        // Callback de Google (anclar cuenta): PUBLICO (lo llama Google tras el
        // consentimiento). No puede ir bajo auth:sanctum.
        Route::get('/account/google/callback', [AccountController::class, 'googleCallback']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);
            Route::get('/user', fn (Request $request) => $request->user());

            // Autorizacion de canales privados (WebSockets) para el superadmin.
            Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

            // MFA TOTP (segundo factor) del superadministrador de plataforma.
            Route::post('/mfa/setup', [PlatformMfaController::class, 'setup']);
            Route::post('/mfa/confirm', [PlatformMfaController::class, 'confirm']);
            Route::post('/mfa/disable', [PlatformMfaController::class, 'disable']);

            // Ajustes de cuenta del usuario autenticado (perfil, email, clave,
            // desactivacion y vinculacion de Google).
            Route::get('/account', [AccountController::class, 'index']);
            Route::put('/account/profile', [AccountController::class, 'updateProfile']);
            Route::post('/account/email', [AccountController::class, 'changeEmail']);
            Route::post('/account/password', [AccountController::class, 'changePassword']);
            Route::post('/account/deactivate', [AccountController::class, 'deactivate']);
            Route::post('/account/google/connect', [AccountController::class, 'googleConnect']);
            Route::delete('/account/google', [AccountController::class, 'googleUnlink']);

            // Panel del superadministrador (solo plataforma).
            Route::middleware('platform')->group(function () {
                Route::get('/colegios', [ColegioController::class, 'index']);
                Route::post('/colegios', [ColegioController::class, 'store']);
                Route::get('/colegios/{id}', [ColegioController::class, 'show']);
                Route::put('/colegios/{id}', [ColegioController::class, 'update']);
                Route::patch('/colegios/{id}/status', [ColegioController::class, 'updateStatus']);
                Route::patch('/colegios/{id}/plan', [ColegioController::class, 'updatePlan']);
                Route::post('/colegios/{id}/reset-password', [ColegioController::class, 'resetRectorPassword']);
                Route::get('/colegios/{id}/rector-password', [ColegioController::class, 'rectorPassword']);

                // Sedes de un colegio gestionadas por el superadmin (viven en la BD del tenant).
                Route::get('/colegios/{id}/sedes', [ColegioSedeController::class, 'index']);
                Route::post('/colegios/{id}/sedes', [ColegioSedeController::class, 'store']);
                Route::put('/colegios/{id}/sedes/{sedeId}', [ColegioSedeController::class, 'update']);
                Route::delete('/colegios/{id}/sedes/{sedeId}', [ColegioSedeController::class, 'destroy']);

                // Suplantacion / cambio de contexto: entrar y salir de un colegio.
                Route::post('/platform/impersonar', [ImpersonationController::class, 'impersonar']);
                Route::post('/platform/impersonar/salir', [ImpersonationController::class, 'salir']);

                // Planes / membresias (con su catalogo cerrado de features).
                Route::get('/plans', [PlanController::class, 'index']);
                Route::post('/plans', [PlanController::class, 'store']);
                Route::get('/plans/{id}', [PlanController::class, 'show']);
                Route::put('/plans/{id}', [PlanController::class, 'update']);

                // Catalogo RBAC editable (roles, permisos y matriz global).
                Route::get('/rbac/catalog', [RbacController::class, 'catalog']);
                Route::post('/rbac/permissions', [RbacController::class, 'storePermission']);
                Route::put('/rbac/permissions/{id}', [RbacController::class, 'updatePermission']);
                Route::delete('/rbac/permissions/{id}', [RbacController::class, 'destroyPermission']);
                Route::post('/rbac/roles', [RbacController::class, 'storeRole']);
                Route::put('/rbac/roles/{id}', [RbacController::class, 'updateRole']);
                Route::delete('/rbac/roles/{id}', [RbacController::class, 'destroyRole']);
                Route::put('/rbac/matrix', [RbacController::class, 'setMatrixCell']);
            });
        });

        /*
         | Acceso a las rutas del COLEGIO desde el panel central (SIN subdominio).
         |
         | El superadmin suplantando llega a los MISMOS controladores academicos
         | del colegio usando: Authorization: Bearer <token de impersonacion> +
         | header 'X-Tenant: <colegio.id>'. El orden importa:
         |   1. InitializeTenancyByRequestData resuelve el tenant por el header
         |      'X-Tenant' (= tenant key/uuid) y cambia la conexion a la BD del
         |      colegio ANTES de autenticar.
         |   2. auth:sanctum busca el token en la BD del colegio (usuario sombra).
         |
         | Reutiliza routes/tenant_academico.php (las MISMAS rutas del subdominio).
         */
        // Nota: routes/api.php ya se registra con el prefijo global 'api'
        // (bootstrap/app.php -> withRouting(api: ...)), por lo que aqui NO se
        // vuelve a aplicar prefix('api'); de lo contrario las rutas quedarian
        // en /api/api/... y el frontend (que llama a /api/anos-lectivos) daria 404.
        Route::middleware([InitializeTenancyByRequestData::class, 'auth:sanctum'])
            ->group(function () {
                require base_path('routes/tenant_academico.php');
            });
    });
}
