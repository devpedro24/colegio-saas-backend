<?php

namespace Tests\Feature;

use App\Events\ApplicationChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RealtimeQueueCompactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compacts_only_unreserved_broadcasts_and_keeps_other_jobs(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'performance.realtime_queue' => 'default',
        ]);
        $insert = static function (string $name, ?string $tenant, ?int $reserved = null): void {
            DB::connection('sqlite')->table('jobs')->insert([
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => $name,
                    'data' => $name === ApplicationChanged::class ? [
                        'command' => serialize(new BroadcastEvent(new ApplicationChanged($tenant, ['structure']))),
                    ] : [],
                ], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'reserved_at' => $reserved,
                'available_at' => time(),
                'created_at' => time(),
            ]);
        };

        $insert(ApplicationChanged::class, 'school-a');
        $insert(ApplicationChanged::class, 'school-a');
        $insert(ApplicationChanged::class, null);
        $insert(ApplicationChanged::class, 'school-a', time());
        $insert('UnrelatedJob', null);

        $this->assertSame(0, Artisan::call('realtime:compactar-cola'));
        $this->assertSame(5, DB::connection('sqlite')->table('jobs')->count());
        $lastOriginalId = DB::connection('sqlite')->table('jobs')->max('id');
        $this->assertSame(0, Artisan::call('realtime:compactar-cola', ['--apply' => true]));
        $jobs = DB::connection('sqlite')->table('jobs')->get();
        $this->assertCount(4, $jobs);
        $this->assertSame(1, $jobs->whereNotNull('reserved_at')->count());
        $this->assertSame(1, $jobs->filter(fn ($job) => json_decode($job->payload, true)['displayName'] === 'UnrelatedJob')->count());
        $replacements = $jobs->filter(fn ($job) => $job->id > $lastOriginalId && $job->reserved_at === null
            && json_decode($job->payload, true)['displayName'] === ApplicationChanged::class)
            ->map(fn ($job) => unserialize(json_decode($job->payload, true)['data']['command'])->event)
            ->values();
        $this->assertSame(['school-a'], $replacements->pluck('tenantId')->all());
        $this->assertSame([['all']], $replacements->pluck('resources')->all());
        $this->assertSame(0, Artisan::call('realtime:compactar-cola', ['--apply' => true]));
        $this->assertSame(4, DB::connection('sqlite')->table('jobs')->count());
    }
}
