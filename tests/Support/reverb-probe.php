<?php

// CLI-only transport probe for the isolated browser fixtures. Never modifies a database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('The transport probe is restricted to local development.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$channelToken = App\Support\Realtime\TenantChannelName::tokenForId('smoke');
if (($input['action'] ?? '') === 'channel') {
    echo json_encode(['token' => $channelToken]);
} elseif (($input['action'] ?? '') === 'auth') {
    if (($input['channel_name'] ?? '') !== 'private-tenant.'.$channelToken) {
        throw new RuntimeException('Only the isolated smoke channel can be signed.');
    }
    echo Illuminate\Support\Facades\Broadcast::connection('reverb')->getPusher()
        ->authorizeChannel($input['channel_name'], $input['socket_id']);
} elseif (($input['action'] ?? '') === 'publish') {
    config(['queue.default' => 'sync']); // Isolated transport test: no real job or database writes.
    $count = $input['count'] ?? 1;
    if (! is_int($count) || $count < 1 || $count > 50) {
        throw new RuntimeException('Invalid isolated broadcast count.');
    }
    for ($i = 0; $i < $count; $i++) {
        App\Events\ApplicationChanged::dispatch('smoke', $input['resources'] ?? ['all']);
    }
    echo json_encode(['published' => $count]);
} else {
    throw new RuntimeException('Unknown probe action.');
}
