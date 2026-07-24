<?php

use App\Http\Controllers\Api\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Api\Platform\ColegioController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Controllers\Api\Platform\RbacController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

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

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);
            Route::get('/user', fn (Request $request) => $request->user());

            // Autorizacion de canales privados (WebSockets) para el superadmin.
            Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

            // Panel del superadministrador (solo plataforma).
            Route::middleware('platform')->group(function () {
                Route::get('/colegios', [ColegioController::class, 'index']);
                Route::post('/colegios', [ColegioController::class, 'store']);
                Route::get('/colegios/{id}', [ColegioController::class, 'show']);
                Route::put('/colegios/{id}', [ColegioController::class, 'update']);
                Route::patch('/colegios/{id}/status', [ColegioController::class, 'updateStatus']);
                Route::patch('/colegios/{id}/plan', [ColegioController::class, 'updatePlan']);
                Route::post('/colegios/{id}/reset-password', [ColegioController::class, 'resetRectorPassword']);

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
    });
}
