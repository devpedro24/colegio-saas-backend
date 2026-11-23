<?php

declare(strict_types=1);

use App\Jobs\SendEnrollmentNotice;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\DatabaseConfig;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$database = config('database.connections.pgsql.database');
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! preg_match('/^codex_sedes_test_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('A disposable enrollment test database is required.');
}
DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->id), 0, 10));
Queue::fake();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$request = Request::create('http://inscripciones.localhost/api/ingreso/'.$input['path'], 'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'es',
        'HTTP_ORIGIN' => 'http://inscripciones.localhost', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['session']],
    content: json_encode($input['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'message' => $body['message'] ?? null,
    'assignments' => $body['asignaciones'] ?? [], 'application' => $body['data']['url_token'] ?? null,
    'state' => $body['data']['estado'] ?? null, 'notices' => Queue::pushed(SendEnrollmentNotice::class)->pluck('notice')->all()], JSON_THROW_ON_ERROR);
