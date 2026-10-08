<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\Platform\AuditController;
use App\Http\Controllers\Api\Platform\AulaOfficeCallbackController;
use App\Http\Controllers\Api\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Api\Platform\ColegioController;
use App\Http\Controllers\Api\Platform\ColegioSedeController;
use App\Http\Controllers\Api\Platform\ImpersonationController;
use App\Http\Controllers\Api\Platform\MfaController as PlatformMfaController;
use App\Http\Controllers\Api\Platform\PlanController;
use App\Http\Controllers\Api\Platform\RbacController;
use App\Http\Controllers\Api\Platform\StorageController;
use App\Http\Middleware\EnsureMfaReady;
use App\Support\Account\AccountPresenter;
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
        Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:account-security');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:account-security');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:account-security');

        // Descarga de archivos del colegio con URL FIRMADA de corta vida
        // (RN-AC-004): la firma incluye el tenant dueno para impedir cruces.
        Route::get('/storage/{file}/download', [StorageController::class, 'download'])
            ->where('file', '[A-Za-z0-9_-]{24}')
            ->middleware('signed')
            ->name('storage.file');

        // ONLYOFFICE reaches the API through a different internal host in
        // Docker. A separate, short-lived relative signature keeps this URL
        // valid without weakening signatures for ordinary file downloads.
        Route::get('/aula/office-file/{file}/download', [StorageController::class, 'download'])
            ->where('file', '[A-Za-z0-9_-]{24}')
            ->middleware('signed:relative')
            ->name('storage.office-file');

        Route::post('/aula/office-callback', AulaOfficeCallbackController::class)
            ->middleware('throttle:60,1')->name('aula.office-callback');

        // Callback de Google (anclar cuenta): PUBLICO (lo llama Google tras el
        // consentimiento). No puede ir bajo auth:sanctum.
        Route::get('/account/google/callback', [AccountController::class, 'googleCallback']);

        Route::middleware(['auth:sanctum', EnsureMfaReady::class])->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);
            Route::get('/user', fn (Request $request) => response()->json(AccountPresenter::user($request->user())));

            // Autorizacion de canales privados (WebSockets) para el superadmin.
            Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

            // MFA TOTP (segundo factor) del superadministrador de plataforma.
            Route::post('/mfa/setup', [PlatformMfaController::class, 'setup'])->middleware('throttle:mfa');
            Route::post('/mfa/confirm', [PlatformMfaController::class, 'confirm'])->middleware('throttle:mfa');
            Route::post('/mfa/disable', [PlatformMfaController::class, 'disable'])->middleware('throttle:mfa');

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
                Route::get('/platform/correo-solicitudes/resumen', [\App\Http\Controllers\Api\Platform\SchoolMailRequestsController::class, 'summary']);
                Route::get('/platform/correo-solicitudes', [\App\Http\Controllers\Api\Platform\SchoolMailRequestsController::class, 'index']);
                Route::post('/platform/correo-solicitudes/{token}/resolver', [\App\Http\Controllers\Api\Platform\SchoolMailRequestsController::class, 'resolve'])->where('token', '[A-Za-z0-9_-]{24}');
                Route::get('/platform/auditoria', [AuditController::class, 'index']);
                Route::get('/platform/auditoria/colegios', [AuditController::class, 'colegios']);
                Route::get('/colegios', [ColegioController::class, 'index']);
                Route::post('/colegios', [ColegioController::class, 'store']);
                Route::get('/colegios/{slug}', [ColegioController::class, 'show'])->where('slug', '[a-z][a-z0-9-]*');
                Route::put('/colegios/{slug}', [ColegioController::class, 'update'])->where('slug', '[a-z][a-z0-9-]*');
                Route::patch('/colegios/{slug}/status', [ColegioController::class, 'updateStatus'])->where('slug', '[a-z][a-z0-9-]*');
                Route::patch('/colegios/{slug}/plan', [ColegioController::class, 'updatePlan'])->where('slug', '[a-z][a-z0-9-]*');
                Route::post('/colegios/{slug}/reset-password', [ColegioController::class, 'resetRectorPassword'])->where('slug', '[a-z][a-z0-9-]*');
                Route::get('/colegios/{slug}/rector-password', [ColegioController::class, 'rectorPassword'])->where('slug', '[a-z][a-z0-9-]*');

                // Sedes de un colegio gestionadas por el superadmin (viven en la BD del tenant).
                Route::get('/colegios/{slug}/sedes', [ColegioSedeController::class, 'index'])->where('slug', '[a-z][a-z0-9-]*');
                Route::post('/colegios/{slug}/sedes', [ColegioSedeController::class, 'store'])->where('slug', '[a-z][a-z0-9-]*');
                Route::put('/colegios/{slug}/sedes/{sedeToken}', [ColegioSedeController::class, 'update'])->where(['slug' => '[a-z][a-z0-9-]*', 'sedeToken' => '[A-Za-z0-9_-]{24}']);
                Route::delete('/colegios/{slug}/sedes/{sedeToken}', [ColegioSedeController::class, 'destroy'])->where(['slug' => '[a-z][a-z0-9-]*', 'sedeToken' => '[A-Za-z0-9_-]{24}']);

                // Suplantacion / cambio de contexto: entrar y salir de un colegio.
                Route::post('/platform/impersonar', [ImpersonationController::class, 'impersonar']);
                Route::post('/platform/impersonar/salir', [ImpersonationController::class, 'salir']);
                Route::get('/platform/impersonar/estado', [ImpersonationController::class, 'estado']);

                // Planes / membresias (con su catalogo cerrado de features).
                Route::get('/plans', [PlanController::class, 'index']);
                Route::post('/plans', [PlanController::class, 'store']);
                Route::get('/plans/{key}', [PlanController::class, 'show'])->where('key', '[a-z][a-z0-9-]*');
                Route::put('/plans/{key}', [PlanController::class, 'update'])->where('key', '[a-z][a-z0-9-]*');

                // Catalogo RBAC editable (roles, permisos y matriz global).
                Route::get('/rbac/catalog', [RbacController::class, 'catalog']);
                Route::post('/rbac/permissions', [RbacController::class, 'storePermission']);
                Route::put('/rbac/permissions/{key}', [RbacController::class, 'updatePermission'])->where('key', '[a-z][a-z0-9._-]*');
                Route::delete('/rbac/permissions/{key}', [RbacController::class, 'destroyPermission'])->where('key', '[a-z][a-z0-9._-]*');
                Route::post('/rbac/roles', [RbacController::class, 'storeRole']);
                Route::put('/rbac/roles/{key}', [RbacController::class, 'updateRole'])->where('key', '[a-z][a-z0-9_]*');
                Route::delete('/rbac/roles/{key}', [RbacController::class, 'destroyRole'])->where('key', '[a-z][a-z0-9_]*');
                Route::put('/rbac/matrix', [RbacController::class, 'setMatrixCell']);
            });
        });

        /*
         | Acceso a las rutas del COLEGIO desde el panel central (SIN subdominio).
         |
         | El superadmin suplantando llega a los MISMOS controladores académicos
         | mediante la sesión de suplantación en cookie HttpOnly. El orden importa:
         |   1. InitializeTenancyByRequestData resuelve el colegio a partir de
         |      la sesión y cambia la conexión antes de autenticar.
         |   2. auth:sanctum identifica al usuario sombra en la BD del colegio.
         |
         | Reutiliza routes/tenant_academico.php (las MISMAS rutas del subdominio).
         */
        // Nota: routes/api.php ya se registra con el prefijo global 'api'
        // (bootstrap/app.php -> withRouting(api: ...)), por lo que aqui NO se
        // vuelve a aplicar prefix('api'); de lo contrario las rutas quedarian
        // en /api/api/... y el frontend (que llama a /api/anos-lectivos) daria 404.
        Route::middleware([InitializeTenancyByRequestData::class, 'auth:sanctum', EnsureMfaReady::class])
            ->group(function () {
                Route::post('/tenant-broadcasting/auth', fn (Request $request) => Broadcast::auth($request));
                require base_path('routes/tenant_academico.php');
            });
    });
}
