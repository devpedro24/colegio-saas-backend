<?php

declare(strict_types=1);

// Local operator diagnostic. Never creates an event, student or recipient list.
use App\Jobs\SendEventMailPreview;
use App\Models\Tenant;
use App\Services\SchoolMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, "Event mail diagnostic failed; no transport or credential details are printed.\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || ! $app->environment('local')) exit(1);
$options = getopt('', ['inspect', 'enqueue', 'tenant:', 'to:', 'status:']);
$connection = config('queue.connections.event_mail.connection');
$database = DB::connection($connection);
$queueTable = config('queue.connections.event_mail.table');
if (isset($options['inspect'])) {
    $schools = [];
    foreach (Tenant::where('status', Tenant::STATUS_ACTIVE)->get() as $school) {
        $schools[] = $school->run(fn () => [
            'slug' => $school->slug,
            'mail_configured' => app(SchoolMail::class)->settings() !== null,
            'delivery_states' => Schema::hasTable('evento_correos')
                ? DB::table('evento_correos')->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->pluck('total', 'estado')->all() : null,
        ]);
    }
    echo json_encode(['environment' => app()->environment(), 'connection' => config('events.connection'),
        'driver' => config('queue.connections.event_mail.driver'), 'cache' => config('cache.default'),
        'event_jobs' => $database->table($queueTable)->where('queue', 'event-mail')->count(),
        'failed_event_jobs' => $database->table('failed_jobs')->where('queue', 'event-mail')->count(),
        'schools' => $schools], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
if (isset($options['status'])) {
    if (! preg_match('/^[a-f0-9]{24}$/D', $options['status'])) exit(1);
    echo json_encode(Cache::get('event-mail-preview:'.$options['status'], ['status' => 'unknown']), JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}
if (! isset($options['enqueue'], $options['tenant'], $options['to'])
    || ! filter_var($options['to'], FILTER_VALIDATE_EMAIL)) exit(1);
if (config('events.connection') !== 'event_mail') throw new RuntimeException;
$school = Tenant::where('slug', $options['tenant'])->where('status', Tenant::STATUS_ACTIVE)->firstOrFail();
$school->run(function (): void {
    if (! app(SchoolMail::class)->readyForEvents()) throw new RuntimeException;
});
$receipt = bin2hex(random_bytes(12));
Cache::put('event-mail-preview:'.$receipt, ['status' => 'queued', 'queued_at' => now()->toIso8601String()], now()->addDay());
SendEventMailPreview::dispatch((string) $school->getKey(), $options['to'], $receipt);
echo json_encode(['receipt' => $receipt, 'status' => 'queued', 'recipients' => 1,
    'creates_event_or_student' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
