<?php

declare(strict_types=1);

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
