<?php

declare(strict_types=1);

// Opt-in local end-to-end mail diagnostic. Sends one synthetic notice only to
// the school's already configured sender, on a private queue, then removes
// only the synthetic notification and diagnostic queue rows.
use App\Jobs\SendEnrollmentNotice;
use App\Models\Ingreso\Notificacion;
use App\Models\Tenant;
use App\Services\SchoolMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (PHP_SAPI !== 'cli' || ! $app->environment('local') || ! in_array('--send-test', $argv, true)) {
    exit("Run only in the local environment with --send-test.\n");
}
if (config('queue.default') !== 'database') {
    exit("The local default queue is not database.\n");
}
$connection = (string) config('queue.connections.database.connection');
$table = (string) config('queue.connections.database.table');
if (config("database.connections.{$connection}.driver") !== 'pgsql') {
    exit("The configured queue database is not PostgreSQL.\n");
}

$school = Tenant::where('slug', 'inmaculada')->firstOrFail();
$nonce = bin2hex(random_bytes(12));
$queue = 'readiness_mail_'.$nonce;
$key = 'diagnostico:cola:'.$nonce;
$database = DB::connection($connection);
$noticeId = null;
$passed = false;
$report = ['synthetic_only' => true, 'isolated_queue' => true,
    'recipient_is_configured_sender' => true, 'worker_exited_successfully' => false,
    'notice_marked_sent' => false, 'failed_jobs' => null,
    'stored_credential_unchanged' => false];

try {
    $before = $school->run(function () use ($key): array {
        $sender = app(SchoolMail::class)->settings();
        if (! $sender) {
            throw new RuntimeException('The school sender is not configured.');
        }
        $record = DB::table('correo_configuracion')->where('key', 'gmail')->first();
        $notice = Notificacion::create(['clave' => $key, 'email' => $sender->email,
            'asunto' => 'Prueba de cola de matrícula',
            'contenido' => 'Este mensaje ficticio comprueba la cola y el diseño del correo. No se creó ninguna matrícula.']);

        return ['notice' => $notice->id, 'sender' => json_encode($record, JSON_THROW_ON_ERROR)];
    });
    $noticeId = $before['notice'];
    Queue::connection('database')->push(new SendEnrollmentNotice((string) $school->getTenantKey(), $noticeId), '', $queue);
    if ($database->table($table)->where('queue', $queue)->count() !== 1) {
        throw new RuntimeException('The synthetic notice was not queued.');
    }
    $worker = new Process([
        PHP_BINARY, base_path('artisan'), 'queue:work', 'database',
        '--queue='.$queue, '--once', '--tries=1', '--timeout=30', '--no-interaction',
    ], base_path());
    $worker->setTimeout(45);
    $worker->run();
    $report['worker_exited_successfully'] = $worker->isSuccessful();
    $report['failed_jobs'] = (int) $database->table('failed_jobs')->where('queue', $queue)->count();
    $after = $school->run(function () use ($noticeId): array {
        $notice = Notificacion::findOrFail($noticeId);
        $record = DB::table('correo_configuracion')->where('key', 'gmail')->first();

        return ['sent' => $notice->enviada_en !== null && $notice->contenido === null,
            'sender' => json_encode($record, JSON_THROW_ON_ERROR)];
    });
    $report['notice_marked_sent'] = $after['sent'];
    $report['stored_credential_unchanged'] = $before['sender'] === $after['sender'];
    $passed = $report['worker_exited_successfully'] && $report['notice_marked_sent']
        && $report['failed_jobs'] === 0 && $report['stored_credential_unchanged']
        && $database->table($table)->where('queue', $queue)->count() === 0;
} catch (Throwable) {
    // Mail and database exceptions can contain addresses or transport details.
    $report['diagnostic_error'] = 'The synthetic queued mail check did not complete.';
} finally {
    $database->table($table)->where('queue', $queue)->delete();
    $database->table('failed_jobs')->where('queue', $queue)->delete();
    if ($noticeId !== null) {
        $school->run(fn () => Notificacion::whereKey($noticeId)->where('clave', $key)->delete());
    }
}

$report['passed'] = $passed;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($passed ? 0 : 1);
