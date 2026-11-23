<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureOnboardingComplete;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\DatabaseConfig;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$database = config('database.connections.pgsql.database');
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! preg_match('/^codex_notifications_test_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('A disposable notification database is required.');
}
DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 10));
$app->instance(EnsureOnboardingComplete::class, new class {
    public function handle($request, $next) { return $next($request); }
});
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Carbon::setTestNow(Carbon::createFromTimestampUTC($input['time']));
$request = Request::create($input['url'], 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], content: json_encode($input['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo $response->getStatusCode()."\n";
