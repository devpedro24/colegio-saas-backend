<?php

declare(strict_types=1);

// Disposable databases only. No migrations or tests against the application database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql') throw new RuntimeException('PostgreSQL is required.');
$admin = Illuminate\Support\Facades\DB::connection('pgsql')->getPdo();
$database = 'codex_recovery_test_'.bin2hex(random_bytes(5));
$admin->exec('CREATE DATABASE "'.$database.'"');
$exit = 1;
try {
    $env = ['RECOVERY_POSTGRES_TEST' => '1', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database,
        'DB_CACHE_CONNECTION' => 'pgsql', 'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => dirname(__DIR__).'/bootstrap/cache/'.$database.'.php'];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $name = ['username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'host' => 'DB_HOST', 'port' => 'DB_PORT'][$key];
        $env[$name] = (string) config('database.connections.pgsql.'.$key);
    }
    $process = new Symfony\Component\Process\Process([PHP_BINARY, 'vendor/bin/phpunit', 'tests/Feature/PasswordRecoveryTenantTest.php'], dirname(__DIR__), $env);
    $process->setTimeout(180)->run(fn ($type, $buffer) => print($buffer));
    $exit = $process->getExitCode() ?? 1;
} finally {
    $query = $admin->prepare('SELECT datname FROM pg_database WHERE datname = ? OR datname LIKE ?');
    $query->execute([$database, $database.'_t_%']);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (! preg_match('/^'.preg_quote($database, '/').'(?:_t_[a-f0-9]{10})?$/D', $name)) throw new RuntimeException('Unsafe test database name.');
        $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
        $terminate->execute([$name]);
        $admin->exec('DROP DATABASE "'.$name.'"');
    }
    echo "PostgreSQL: disposable recovery databases removed.\n";
}
exit($exit);
