<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas CENTRALES (plataforma / superadministrador).
|
| Se restringen por dominio a los `central_domains` de config/tenancy.php para
| que NO colisionen con las rutas de tenant (routes/tenant.php), que se
| resuelven por subdominio. Sin esta restriccion, la ruta `/` de tenant
| eclipsaria a la central por orden de registro.
*/

foreach (config('tenancy.central_domains') as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::get('/', function () {
            return response()->json([
                'ok' => true,
                'contexto' => 'central',
            ]);
        });
    });
}
