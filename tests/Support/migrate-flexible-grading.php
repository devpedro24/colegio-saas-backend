<?php

declare(strict_types=1);

// Local deployment drill: keeps verified backups, tests a fresh PostgreSQL
// schema and a restored school before optionally upgrading the real databases.
use App\Models\Tenant;
use App\Support\AcademicDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! in_array('--verify', $argv, true) || ! $app->environment('local')) {
    exit("Solo entorno local: --verify [--apply] --pg-bin=...\n");
}
$bin = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--pg-bin=')) {
        $bin = rtrim(substr($arg, 9), '/\\').DIRECTORY_SEPARATOR;
    }
}
$central = config('tenancy.database.central_connection');
$config = config('database.connections.'.$central);
if ($config['driver'] !== 'pgsql') {
    throw new RuntimeException('Se requiere PostgreSQL.');
}
$admin = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schools = Tenant::orderBy('slug')->get();
if ($schools->isEmpty()) {
    throw new RuntimeException('No hay tenants para verificar una actualización.');
}
$nonce = bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'colegio-preinformes-'.date('Ymd-His').'-'.$nonce;
mkdir($directory, 0700);
$connection = ['--host', $config['host'], '--port', (string) $config['port'], '--username', $config['username'], '--no-password'];
$run = function (array $args) use ($config): string {
    $process = new Process($args, null, ['PGPASSWORD' => $config['password'], 'PGSSLMODE' => $config['sslmode'] ?? 'prefer']);
    $process->setTimeout(180);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Falló una herramienta PostgreSQL; los respaldos se conservaron.');
    }

    return $process->getOutput();
};
$databases = ['central' => $config['database']];
foreach ($schools as $school) {
    $databases[$school->slug] = $school->database()->getName();
}
$dumps = [];
foreach ($databases as $label => $database) {
    $file = $directory.'/'.hash('sha256', $database).'.dump';
    $run([$bin.'pg_dump', ...$connection, '--format=custom', '--no-owner', '--no-acl', '--file', $file, $database]);
    $run([$bin.'pg_restore', '--list', $file]);
    $dumps[$database] = $file;
    echo "Respaldo verificado: {$label}\n";
}
$created = [];
$signature = function (): array {
    return ['users' => DB::table('users')->count(), 'activities' => DB::table('actividades_evaluacion')->count(),
        'grades' => DB::table('calificaciones')->count(),
        'values' => hash('sha256', json_encode(DB::table('calificaciones')->orderBy('id')->get(['id', 'valor'])->map(fn ($row) => [$row->id, AcademicDecimal::normalize($row->valor)])->all()))];
};
try {
    foreach (['fresh', 'upgrade'] as $kind) {
        $name = 'grading_probe_'.$kind.'_'.$nonce;
        if (! preg_match('/^grading_probe_(fresh|upgrade)_[a-f0-9]{12}$/D', $name)) {
            throw new RuntimeException('Nombre inseguro.');
        }
        $admin->exec('CREATE DATABASE "'.$name.'"');
        $created[] = $name;
        if ($kind === 'upgrade') {
            $source = $schools->firstWhere('slug', 'inmaculada') ?? $schools->first();
            $run([$bin.'pg_restore', ...$connection, '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction', '--dbname', $name, $dumps[$source->database()->getName()]]);
        }
        config(['database.connections.grading_probe' => [...$config, 'database' => $name]]);
        DB::purge('grading_probe');
        DB::setDefaultConnection('grading_probe');
        $before = $kind === 'upgrade' ? $signature() : null;
        $exit = Artisan::call('migrate', ['--database' => 'grading_probe', '--path' => 'database/migrations/tenant', '--force' => true]);
        if ($exit !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        if ($before !== null && $before !== $signature()) {
            throw new RuntimeException('La actualización alteró datos existentes.');
        }
        if (! DB::getSchemaBuilder()->hasTable('preinformes')) {
            throw new RuntimeException('Falta preinformes.');
        }
        $column = DB::selectOne("SELECT data_type, numeric_scale FROM information_schema.columns WHERE table_name = 'calificaciones' AND column_name = 'valor'");
        if ($column->data_type !== 'numeric' || $column->numeric_scale !== null) {
            throw new RuntimeException('Escala decimal incorrecta.');
        }
        DB::setDefaultConnection($central);
        DB::purge('grading_probe');
        echo "PostgreSQL {$kind}: correcto; esquema y datos verificados.\n";
    }
} finally {
    DB::setDefaultConnection($central);
    DB::purge('grading_probe');
    foreach (array_reverse($created) as $name) {
        $admin->exec('DROP DATABASE "'.$name.'"');
    }
}
if (in_array('--apply', $argv, true)) {
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    foreach ($schools as $school) {
        $before = $school->run($signature);
        if (Artisan::call('tenants:migrate', ['--tenants' => [$school->id], '--force' => true]) !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        $school->run(function () use ($before, $signature, $school) {
            if ($before !== $signature()) {
                throw new RuntimeException('Cambió la información de un colegio.');
            }
            if (! DB::getSchemaBuilder()->hasTable('preinformes')) {
                throw new RuntimeException('Falta tabla.');
            }
            if (! Permission::where('name', 'notas.actividades.crear')->exists()) {
                throw new RuntimeException('Falta permiso.');
            }
            echo "Actualizado y verificado: {$school->slug}\n";
        });
    }
}
echo json_encode(['backups' => $directory, 'databases' => $databases, 'sha256' => array_map('hash_file', array_fill(0, count($dumps), 'sha256'), array_values($dumps)),
    'probes_removed' => true, 'applied' => in_array('--apply', $argv, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
