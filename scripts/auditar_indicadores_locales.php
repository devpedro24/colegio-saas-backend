<?php

declare(strict_types=1);

/** Inventario de solo lectura antes de retirar la valoración independiente de indicadores. */

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$central = ['rbac_permissions' => DB::table('rbac_permissions')
    ->where('key', 'indicadores.valoraciones.registrar')->count(),
    'rbac_matrix' => DB::table('rbac_matrix')
        ->where('permission_key', 'indicadores.valoraciones.registrar')->count()];
$summary = Tenant::query()->orderBy('slug')->get()->map(function (Tenant $tenant): array {
    return $tenant->run(function () use ($tenant): array {
        return ['tenant' => $tenant->slug, 'database' => DB::connection()->getDatabaseName(),
            'valoraciones' => Schema::hasTable('indicadores_valoraciones')
                ? DB::table('indicadores_valoraciones')->count() : null,
            'politicas' => Schema::hasTable('indicadores_configuracion')
                ? DB::table('indicadores_configuracion')->count() : null,
            'valoraciones_archivadas' => Schema::hasTable('indicadores_valoraciones_legacy')
                ? DB::table('indicadores_valoraciones_legacy')->count() : null,
            'politicas_archivadas' => Schema::hasTable('indicadores_configuracion_legacy')
                ? DB::table('indicadores_configuracion_legacy')->count() : null,
            'vinculos' => Schema::hasTable('indicadores_vinculos')
                ? DB::table('indicadores_vinculos')->count() : null,
            'observaciones' => Schema::hasTable('observaciones_boletin')
                ? DB::table('observaciones_boletin')->count() : null,
            'permiso_retirado' => DB::table('permissions')
                ->where('name', 'indicadores.valoraciones.registrar')->count()];
    });
})->all();

echo json_encode(['central' => $central, 'tenants' => $summary],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
