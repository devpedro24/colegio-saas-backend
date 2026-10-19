<?php

declare(strict_types=1);

/** Respalda central y tenants PostgreSQL locales antes de retirar las tablas antiguas. */

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local')) {
    throw new RuntimeException('Solo se permite respaldar las bases de desarrollo locales.');
}
$dumpBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_dump.exe';
$restoreBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_restore.exe';
if (! is_file($dumpBinary) || ! is_file($restoreBinary)) {
    throw new RuntimeException('No se encontraron pg_dump y pg_restore de PostgreSQL 16.');
}
$destination = base_path('storage/app/local-sample-backups/indicator-retirement-'.date('Ymd-His'));
if (! mkdir($destination, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio de respaldo.');
}
$configs = ['central' => DB::connection()->getConfig()];
foreach (Tenant::query()->orderBy('slug')->get() as $tenant) {
    $configs[$tenant->slug] = $tenant->run(fn (): array => DB::connection()->getConfig());
}
$result = [];
foreach ($configs as $label => $config) {
    if (($config['driver'] ?? null) !== 'pgsql'
        || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException("{$label} no usa PostgreSQL local; se detuvo el respaldo.");
    }
    $path = $destination.'/'.$label.'.dump';
    (new Process([$dumpBinary, '--format=custom', '--no-owner', '--host='.(string) $config['host'],
        '--port='.(string) $config['port'], '--username='.(string) $config['username'],
        '--file='.$path, (string) $config['database']], base_path(),
        ['PGPASSWORD' => (string) $config['password']], null, 300))->mustRun();
    if (! is_file($path) || filesize($path) < 1024) {
        throw new RuntimeException("El respaldo de {$label} quedó vacío.");
    }
    (new Process([$restoreBinary, '--list', $path], base_path(), null, null, 60))->mustRun();
    $result[$label] = ['path' => $path, 'bytes' => filesize($path)];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
