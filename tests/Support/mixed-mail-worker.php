<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\DatabaseConfig;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.pgsql.database');
$enrollment = ($argv[4] ?? '') === 'enrollment';
$prefix = $enrollment ? 'codex_sedes_test_' : 'codex_notifications_test_';
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! preg_match('/^'.$prefix.'[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('A disposable notification database is required.');
}
DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 10));
config(['mail.default' => 'load-test', 'mail.mailers.load-test' => ['transport' => 'smtp'],
    'mail.from.address' => 'school.queue.test@gmail.com', 'cache.default' => 'database']);
if (in_array($argv[1], ['--crash-after-acceptance', '--crash-before-smtp', '--deliver'], true)) {
    config(['mail.mailers.load-test' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) $argv[2], 'timeout' => 5]]);
    Carbon::setTestNow(Carbon::createFromTimestampUTC((int) $argv[3]));
    // MessageSent runs after the SMTP server's final 250 and before the outbox acknowledgment.
    $failurePoint = $argv[1] === '--crash-before-smtp' ? \Illuminate\Mail\Events\MessageSending::class : MessageSent::class;
    if ($argv[1] !== '--deliver') {
        Event::listen($failurePoint, static function (): never { exit(75); });
    }
    if ($enrollment) {
        config(['queue.connections.enrollment-review' => [...config('queue.connections.database'), 'connection' => 'pgsql']]);
    }
    app('queue.worker')->setCache(app('cache.store'))->runNextJob($enrollment ? 'enrollment-review' : 'event_mail',
        $enrollment ? $argv[5] : 'event-mail', new WorkerOptions(sleep: 0));
    exit($argv[1] === '--deliver' ? 0 : 1);
}
Mail::extend('smtp', fn () => new ArrayTransport);
$worker = (int) $argv[1];
$job = null;
Queue::before(function (JobProcessing $event) use (&$job) {
    $job = $event->job->getJobId();
});
Event::listen(MessageSent::class, function (MessageSent $event) use ($worker, &$job) {
    $addresses = $event->message->getTo();
    if (count($addresses) !== 1 || ! $job) {
        throw new RuntimeException('Expected one recipient per queued delivery.');
    }
    // No unique index: a repeated SMTP acceptance must be visible to the test.
    DB::connection('pgsql')->table('mail_load_receipts')->insert([
        'recipient' => $addresses[0]->getAddress(), 'subject' => $event->message->getSubject(),
        'accepted' => now()->timestamp, 'job' => $job, 'worker' => $worker,
    ]);
});
$control = DB::connection('pgsql')->table('mail_load_workers')->where('worker', $worker);
$consumer = app('queue.worker')->setCache(app('cache.store'));
$options = new WorkerOptions(sleep: 0);
$control->update(['ready' => true]);
$last = 0;
while (true) {
    $tick = (int) (clone $control)->value('scheduled');
    if ($tick < 0) {
        break;
    }
    if ($tick === $last) {
        usleep(20000);
        continue;
    }
    Carbon::setTestNow(Carbon::createFromTimestampUTC($tick));
    $consumer->runNextJob('event_mail', 'event-mail', $options);
    $last = $tick;
    $control->update(['finished' => $tick]);
}
