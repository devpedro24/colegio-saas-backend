<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ApplicationChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Replace redundant, unreserved cache invalidations with one per scope. */
final class CompactRealtimeQueue extends Command
{
    protected $signature = 'realtime:compactar-cola {--apply : Aplica la compactación (sin esta opción solo informa)}';

    protected $description = 'Agrupa avisos de cambio pendientes sin tocar otros trabajos ni datos del colegio.';

    public function handle(): int
    {
        if (config('queue.default') !== 'database' || config('queue.connections.database.driver') !== 'database') {
            $this->error('La compactación solo admite QUEUE_CONNECTION=database.');

            return self::FAILURE;
        }

        $connection = DB::connection(config('queue.connections.database.connection'));
        $table = (string) config('queue.connections.database.table', 'jobs');
        $queue = (string) config('performance.realtime_queue', 'default');

        try {
            $result = $connection->transaction(function () use ($connection, $table, $queue): array {
                $jobs = $connection->table($table)->where('queue', $queue)
                    ->whereNull('reserved_at')->orderBy('id')
                    ->lockForUpdate()->get(['id', 'payload']);
                $byScope = [];
                foreach ($jobs as $job) {
                    $payload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
                    if (($payload['displayName'] ?? null) !== ApplicationChanged::class) {
                        continue;
                    }
                    $serialized = $payload['data']['command'] ?? null;
                    if (! is_string($serialized)) {
                        throw new RuntimeException('Aviso en cola sin comando serializado; no se modificó la cola.');
                    }
                    $broadcast = @unserialize($serialized, [
                        'allowed_classes' => [BroadcastEvent::class, ApplicationChanged::class],
                    ]);
                    if (! $broadcast instanceof BroadcastEvent || ! $broadcast->event instanceof ApplicationChanged) {
                        throw new RuntimeException('Aviso en cola no reconocido; no se modificó la cola.');
                    }
                    $tenantId = $broadcast->event->tenantId;
                    if ($tenantId !== null && $tenantId === '') {
                        throw new RuntimeException('Aviso en cola sin colegio válido; no se modificó la cola.');
                    }
                    $key = $tenantId === null ? 'platform' : 'tenant:'.$tenantId;
                    $byScope[$key]['tenant'] = $tenantId;
                    $byScope[$key]['ids'][] = $job->id;
                }
                $byScope = array_filter($byScope, static fn (array $scope): bool => count($scope['ids']) > 1);

                $total = array_sum(array_map(static fn (array $scope): int => count($scope['ids']), $byScope));
                if (! $this->option('apply')) {
                    return ['pending' => $total, 'scopes' => count($byScope), 'replaced' => 0];
                }

                foreach ($byScope as $scope) {
                    foreach (array_chunk($scope['ids'], 500) as $ids) {
                        $connection->table($table)->whereIn('id', $ids)->whereNull('reserved_at')->delete();
                    }
                    // Same central DB transaction: deletion and replacement are atomic.
                    ApplicationChanged::dispatch($scope['tenant'], ['all']);
                }

                return ['pending' => $total, 'scopes' => count($byScope), 'replaced' => $total];
            });
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $mode = $this->option('apply') ? 'Aplicado' : 'Vista previa';
        $this->info("{$mode}: {$result['pending']} avisos redundantes en {$result['scopes']} alcance(s).");
        if ($this->option('apply')) {
            $this->info("Se sustituyeron {$result['replaced']} avisos por {$result['scopes']} invalidación(es) completas; otros trabajos intactos.");
        }

        return self::SUCCESS;
    }
}
