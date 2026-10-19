<?php

declare(strict_types=1);

// Local diagnostic: run one harmless job on a randomly named queue in the
// central PostgreSQL database. Never consume the application's normal queues.
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (PHP_SAPI !== 'cli' || ! $app->environment('local')
    || (! in_array('--run', $argv, true) && ! in_array('--inspect', $argv, true))) {
    exit("This diagnostic runs only in the local environment with --run or --inspect.\n");
}
if (config('queue.default') !== 'database') {
    exit("The local default queue is not database.\n");
}
$connection = (string) config('queue.connections.database.connection');
$table = (string) config('queue.connections.database.table');
if (config("database.connections.{$connection}.driver") !== 'pgsql') {
    exit("The configured queue database is not PostgreSQL.\n");
}

$queue = 'readiness_'.bin2hex(random_bytes(12));
$database = DB::connection($connection);
$queued = fn () => (int) $database->table($table)->where('queue', $queue)->count();
$failed = fn () => (int) $database->table('failed_jobs')->where('queue', $queue)->count();
$jobTypes = function (string $source) use ($database): array {
    return $database->table($source)
        ->selectRaw("payload::jsonb->>'displayName' AS job_type, COUNT(*) AS total")
        ->groupByRaw("payload::jsonb->>'displayName'")
        ->orderBy('job_type')
        ->get()
        ->map(fn ($row) => ['job_type' => $row->job_type, 'count' => (int) $row->total])
        ->all();
};
if (in_array('--inspect', $argv, true)) {
    $failedKinds = $database->table('failed_jobs')->get(['exception'])->map(function ($row) {
        return preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]+):/', (string) $row->exception, $match)
            ? $match[1] : 'unclassified';
    })->countBy()->all();
    $inmaculada = Tenant::where('slug', 'inmaculada')->first();
    $pendingEnrollmentNotices = $inmaculada?->run(
        fn () => DB::table('ingreso_notificaciones')->whereNull('enviada_en')
            ->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest_created_at')
            ->first()
    );
    $failedDates = $database->table('failed_jobs')
        ->selectRaw("payload::jsonb->>'displayName' AS job_type, MAX(failed_at) AS last_failed_at")
        ->groupByRaw("payload::jsonb->>'displayName'")
        ->orderBy('job_type')->get()->map(fn ($row) => [
            'job_type' => $row->job_type, 'last_failed_at' => $row->last_failed_at,
        ])->all();
    echo json_encode(['queue_driver' => 'database', 'database_driver' => 'pgsql',
        'pending_types' => $jobTypes($table), 'failed_types' => $jobTypes('failed_jobs'),
        'failed_error_classes' => $failedKinds, 'failed_last_at' => $failedDates,
        'inmaculada_unsent_enrollment_notices' => $pendingEnrollmentNotices
            ? ['total' => (int) $pendingEnrollmentNotices->total,
                'oldest_created_at' => $pendingEnrollmentNotices->oldest_created_at] : null,
        'oldest_pending_minutes' => ($earliest = $database->table($table)->min('available_at'))
            ? (int) floor((time() - (int) $earliest) / 60) : null,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
$report = ['queue_driver' => 'database', 'database_driver' => 'pgsql', 'isolated_queue' => true,
    'existing_jobs' => [
        'default' => (int) $database->table($table)->where('queue', 'default')->count(),
        'realtime' => (int) $database->table($table)->where('queue', 'realtime')->count(),
        'other' => (int) $database->table($table)->whereNotIn('queue', ['default', 'realtime', $queue])->count(),
    ],
    'existing_failed_jobs' => (int) $database->table('failed_jobs')->count()];

try {
    if ($queued() !== 0 || $failed() !== 0) {
        throw new RuntimeException('The diagnostic queue was not empty.');
    }
    Queue::connection('database')->push(CallQueuedClosure::create(static function (): void {}), '', $queue);
    $report['job_enqueued'] = $queued() === 1;
    if (! $report['job_enqueued']) {
        throw new RuntimeException('The diagnostic job was not enqueued.');
    }

    $worker = new Process([
        PHP_BINARY, base_path('artisan'), 'queue:work', 'database',
        '--queue='.$queue, '--once', '--tries=1', '--timeout=30', '--no-interaction',
    ], base_path());
    $worker->setTimeout(45);
    $worker->run();
    $report['worker_exited_successfully'] = $worker->isSuccessful();
    $report['job_consumed'] = $queued() === 0;
    $report['failed_jobs'] = $failed();
    $report['passed'] = $report['worker_exited_successfully']
        && $report['job_consumed'] && $report['failed_jobs'] === 0;
} finally {
    // The only rows removed here belong to this randomly named diagnostic queue.
    $database->table($table)->where('queue', $queue)->delete();
    $database->table('failed_jobs')->where('queue', $queue)->delete();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['passed'] ? 0 : 1);
