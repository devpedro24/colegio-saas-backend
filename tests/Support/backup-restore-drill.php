<?php

// Simulacro local con datos sintéticos. No lee ni restaura datos de ningún colegio.
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! in_array('--run', $argv, true)) {
    exit("Usa --run para crear y eliminar dos bases aisladas de prueba.\n");
}
$bin = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--pg-bin=')) {
        $bin = rtrim(substr($arg, 9), '/\\').DIRECTORY_SEPARATOR;
    }
}
$config = config('database.connections.'.config('tenancy.database.central_connection'));
if (($config['driver'] ?? '') !== 'pgsql') {
    throw new RuntimeException('El simulacro requiere PostgreSQL.');
}
$connect = fn (string $db) => new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$db,
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin = $connect($config['database']);
$nonce = bin2hex(random_bytes(8));
$source = 'restore_probe_src_'.$nonce;
$target = 'restore_probe_dst_'.$nonce;
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'school-backup-drill-'.$nonce;
mkdir($directory, 0700);
$created = [];
$db = null;
$started = microtime(true);
$fixture = "Archivo sintético de comprobación; no contiene datos personales.\n";
$run = function (array $args) use ($config): void {
    $process = new Process($args, null, ['PGPASSWORD' => $config['password'], 'PGSSLMODE' => $config['sslmode'] ?? 'prefer']);
    $process->setTimeout(120);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Falló una herramienta PostgreSQL; revisa instalación, conexión y privilegios de las bases de prueba.');
    }
};
try {
    foreach ([$source, $target] as $name) {
        if (! preg_match('/^restore_probe_(src|dst)_[a-f0-9]{16}$/D', $name)) {
            throw new RuntimeException('Nombre inseguro.');
        }
        $admin->exec('CREATE DATABASE "'.$name.'"');
        $created[] = $name;
    }
    $db = $connect($source);
    $db->exec('CREATE TABLE drill_records (id integer PRIMARY KEY, label text NOT NULL)');
    $db->exec("INSERT INTO drill_records VALUES (1, 'Año sintético'), (2, 'Grupo sintético')");
    $expected = $db->query('SELECT * FROM drill_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $db = null;
    $connection = ['--host', $config['host'], '--port', (string) $config['port'], '--username', $config['username'], '--no-password'];
    $run([$bin.'pg_dump', ...$connection, '--format=custom', '--no-owner', '--no-acl', '--file', $directory.'/source.dump', $source]);
    $dump = file_get_contents($directory.'/source.dump');
    $manifest = ['tenant' => 'synthetic-only', 'database_sha256' => hash('sha256', $dump), 'file_sha256' => hash('sha256', $fixture)];
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $iv = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox(json_encode(['manifest' => $manifest, 'dump' => base64_encode($dump), 'file' => $fixture], JSON_THROW_ON_ERROR), $iv, $key);
    file_put_contents($directory.'/encrypted.backup', $iv.$cipher);
    $tampered = $cipher;
    $tampered[20] = chr(ord($tampered[20]) ^ 1);
    if (sodium_crypto_secretbox_open($tampered, $iv, $key) !== false) {
        throw new RuntimeException('Se aceptó un respaldo alterado.');
    }
    $stored = file_get_contents($directory.'/encrypted.backup');
    $plain = sodium_crypto_secretbox_open(substr($stored, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($stored, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    sodium_memzero($key);
    $restored = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    $restoredDump = base64_decode($restored['dump'], true);
    if (hash('sha256', $restoredDump) !== $manifest['database_sha256'] || hash('sha256', $restored['file']) !== $manifest['file_sha256']) {
        throw new RuntimeException('Integridad incorrecta.');
    }
    file_put_contents($directory.'/restored.dump', $restoredDump);
    $run([$bin.'pg_restore', ...$connection, '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction', '--dbname', $target, $directory.'/restored.dump']);
    $db = $connect($target);
    if ($db->query('SELECT * FROM drill_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
        throw new RuntimeException('Los registros restaurados difieren.');
    }
    $db = null;
    $report = ['result' => 'pass', 'synthetic_only' => true, 'records' => count($expected), 'file_hash_verified' => true,
        'tampered_backup_rejected' => true, 'duration_seconds' => round(microtime(true) - $started, 2),
        'scope' => 'Prueba local de herramientas; no certifica respaldos automáticos, retención, PITR ni RPO/RTO productivos.'];
} finally {
    $db = null;
    foreach (array_reverse($created) as $name) {
        $admin->exec('DROP DATABASE "'.$name.'"');
    }
    foreach (['source.dump', 'restored.dump', 'encrypted.backup'] as $file) {
        if (is_file($directory.'/'.$file)) {
            unlink($directory.'/'.$file);
        }
    }
    rmdir($directory);
}
$report['temporary_databases_removed'] = true;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
