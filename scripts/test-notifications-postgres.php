<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql') {
    throw new RuntimeException('Configure PostgreSQL before running this integration check.');
}
$admin = DB::connection('pgsql')->getPdo();
$database = 'codex_notifications_test_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE "'.$database.'"');
$exit = 1;
try {
    $env = ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => dirname(__DIR__).'/bootstrap/cache/'.$database.'.php'];
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $name = ['username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'host' => 'DB_HOST', 'port' => 'DB_PORT'][$key];
        $env[$name] = (string) config('database.connections.pgsql.'.$key);
    }
    $files = in_array('--events', $argv, true) ? ['tests/Feature/EventNotificationTest.php'] : (in_array('--attendance', $argv, true) ? ['tests/Feature/AttendanceNotificationTest.php']
        : (in_array('--aula', $argv, true) ? ['tests/Feature/AulaNotificationTest.php']
            : ['tests/Feature/NotificationRecordTest.php', 'tests/Feature/AulaNotificationTest.php', 'tests/Feature/AttendanceNotificationTest.php', 'tests/Feature/EventNotificationTest.php']));
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--filter=')) {
            $options[] = $argument;
        }
        if ($argument === '--stop-on-failure') {
            $options[] = $argument;
        }
    }
    $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', ...$files, ...$options], dirname(__DIR__), $env);
    $process->setTimeout(600)->run(fn ($type, $buffer) => print ($buffer));
    $exit = $process->getExitCode() ?? 1;
} finally {
    $query = $admin->prepare('SELECT datname FROM pg_database WHERE datname = ? OR datname LIKE ?');
    $query->execute([$database, $database.'_t_%']);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (! preg_match('/^'.preg_quote($database, '/').'(?:_t_[a-f0-9]{10})?$/D', $name)) {
            throw new RuntimeException('Refusing to remove a database outside this test run.');
        }
        $terminate = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
        $terminate->execute([$name]);
        $admin->exec('DROP DATABASE "'.$name.'"');
    }
    echo "PostgreSQL: disposable notification test databases removed.\n";
}
exit($exit);
