<?php

declare(strict_types=1);

/** Inventario o limpieza explícita de textos de indicadores en todos los tenants PostgreSQL locales. */

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local')) {
    throw new RuntimeException('Esta limpieza solo se permite en desarrollo local.');
}
$apply = in_array('--apply', $argv, true);
$backupArg = collect($argv)->first(fn (string $arg) => str_starts_with($arg, '--backup='));
$backupDir = $backupArg ? realpath(substr($backupArg, strlen('--backup='))) : false;
$safeRoot = realpath(base_path('storage/app/local-sample-backups'));
if ($apply && (! $backupDir || ! $safeRoot || ! str_starts_with($backupDir, $safeRoot.DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename($backupDir), 'indicator-retirement-'))) {
    throw new RuntimeException('Para aplicar, indica un respaldo verificado bajo storage/app/local-sample-backups.');
}
$tables = ['indicadores_contenidos_grupo', 'indicadores_variantes', 'indicadores_vinculos',
    'indicadores_revisiones', 'indicadores_base'];
$protected = ['politicas_indicadores', 'observaciones_boletin', 'boletin_emisiones'];
$tenants = Tenant::query()->orderBy('slug')->get();
$configs = ['central' => DB::connection()->getConfig()];
foreach ($tenants as $tenant) {
    $configs[$tenant->slug] = $tenant->run(fn (): array => DB::connection()->getConfig());
}
$restore = 'C:/Program Files/PostgreSQL/16/bin/pg_restore.exe';
foreach ($configs as $slug => $config) {
    if (($config['driver'] ?? null) !== 'pgsql'
        || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException("{$slug}: la base no es PostgreSQL local.");
    }
    if ($apply) {
        $dump = $backupDir.DIRECTORY_SEPARATOR.$slug.'.dump';
        if (! is_file($restore) || ! is_file($dump) || filesize($dump) < 1024) {
            throw new RuntimeException("{$slug}: falta un respaldo válido.");
        }
        (new Process([$restore, '--list', $dump], base_path(), null, null, 60))->mustRun();
    }
}

$inspect = static function () use ($tables, $protected): array {
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Falta la tabla {$table}; migra todos los tenants antes de limpiar.");
        }
    }
    $counts = [];
    foreach ([...$tables, ...$protected] as $table) {
        $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
    }
    $counts['tokens_indicadores'] = DB::table('academic_public_tokens')
        ->whereIn('resource', ['indicador-base', 'indicador-vinculo'])->count();
    $references = DB::select("SELECT source.relname AS source_table, target.relname AS target_table
        FROM pg_constraint constraint_row
        JOIN pg_class source ON source.oid = constraint_row.conrelid
        JOIN pg_class target ON target.oid = constraint_row.confrelid
        WHERE constraint_row.contype = 'f'
          AND target.relname IN ('indicadores_base', 'indicadores_revisiones', 'indicadores_vinculos',
            'indicadores_variantes', 'indicadores_contenidos_grupo')");
    foreach ($references as $reference) {
        if (! in_array($reference->source_table, $tables, true)) {
            throw new RuntimeException("Una tabla ajena ({$reference->source_table}) referencia {$reference->target_table}.");
        }
    }

    return $counts;
};

$inventory = [];
foreach ($tenants as $tenant) {
    $inventory[$tenant->slug] = $tenant->run($inspect);
}
if (! $apply) {
    echo json_encode(['modo' => 'inventario', 'tenants' => $inventory],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$after = [];
foreach ($tenants as $tenant) {
    $after[$tenant->slug] = $tenant->run(static function () use ($tables, $protected, $inspect, $inventory, $tenant): array {
        DB::transaction(static function () use ($tables, $protected, $inventory, $tenant): void {
            $before = $inventory[$tenant->slug];
            foreach ($tables as $table) {
                if (DB::table($table)->count() !== $before[$table]) {
                    throw new RuntimeException("{$tenant->slug}: los indicadores cambiaron desde el inventario.");
                }
            }
            DB::table('indicadores_variantes')->delete();
            DB::table('indicadores_vinculos')->delete();
            DB::table('indicadores_revisiones')->delete();
            DB::table('indicadores_base')->delete();
            DB::table('indicadores_contenidos_grupo')->delete();
            DB::table('academic_public_tokens')->whereIn('resource', ['indicador-base', 'indicador-vinculo'])->delete();
            foreach ($protected as $table) {
                if ($before[$table] !== null && DB::table($table)->count() !== $before[$table]) {
                    throw new RuntimeException("{$tenant->slug}: cambió una tabla protegida ({$table}).");
                }
            }
        });
        $current = $inspect();
        foreach ($tables as $table) {
            if ($current[$table] !== 0) {
                throw new RuntimeException("{$tenant->slug}: la limpieza de {$table} no quedó en cero.");
            }
        }

        return $current;
    });
}
echo json_encode(['modo' => 'limpieza_local', 'respaldo' => $backupDir,
    'antes' => $inventory, 'despues' => $after],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
