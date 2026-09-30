<?php

namespace Tests\Feature;

use App\Events\ApplicationChanged;
use App\Events\TenantDataChanged;
use App\Http\Middleware\SynchronizeRealtimeChanges;
use App\Models\Academico\AnoLectivo;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Realtime\TenantChannelName;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class RealtimeSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.bootstrappers' => [], 'database.connections.tenant' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize(new Tenant(['id' => 'realtime-a', 'status' => 'active']));
        Event::fake([ApplicationChanged::class]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::setDefaultConnection('sqlite');
        DB::purge('tenant');
        parent::tearDown();
    }

    private function year(): AnoLectivo
    {
        return AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'estado' => 'planificado']);
    }

    public function test_invalidation_excludes_only_the_originating_socket_and_uses_the_configured_queue(): void
    {
        $request = Request::create('/api/horarios', 'PUT');
        $request->headers->set('X-Socket-ID', '123.456');
        $this->app->instance('request', $request);
        config(['queue.default' => 'redis', 'performance.realtime_queue' => 'realtime']);
        $event = new ApplicationChanged('realtime-a', ['schedule']);
        $this->assertSame('123.456', $event->socket);
        $this->assertSame(['resources' => ['schedule']], $event->broadcastWith());
        $this->assertSame('realtime', $event->broadcastQueue());
        $this->assertFalse($event->shouldBroadcastNow());
        config(['queue.default' => 'sync']);
        $this->assertTrue($event->shouldBroadcastNow());
    }

    public function test_model_writes_from_commands_publish_without_a_controller(): void
    {
        $year = $this->year();
        $year->update(['nombre' => '2028']);
        $year->delete();
        Event::assertDispatchedTimes(ApplicationChanged::class, 3);
        Event::assertDispatched(ApplicationChanged::class, fn ($event) =>
            $event->tenantId === 'realtime-a' && $event->resources === ['academic']);
    }

    public function test_committed_changes_are_batched_and_rolled_back_changes_are_silent(): void
    {
        $changes = app(RealtimeChanges::class);
        $changes->begin();
        DB::beginTransaction();
        $this->year();
        $changes->flush();
        Event::assertNotDispatched(ApplicationChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(ApplicationChanged::class);

        $changes->begin();
        DB::transaction(function () { $this->year(); TenantDataChanged::dispatch('ano_lectivo', 'created', 'Private name'); });
        Event::assertNotDispatched(ApplicationChanged::class);
        $changes->flush();
        Event::assertDispatchedTimes(ApplicationChanged::class, 1);
    }

    public function test_buffer_preserves_original_tenants_when_the_context_changes(): void
    {
        $changes = app(RealtimeChanges::class);
        $changes->begin();
        $this->year();
        tenancy()->initialize(new Tenant(['id' => 'realtime-b', 'status' => 'active']));
        AnoLectivo::firstOrFail()->update(['nombre' => '2028']);
        tenancy()->end();
        $changes->flush();
        Event::assertDispatchedTimes(ApplicationChanged::class, 2);
        foreach (['realtime-a', 'realtime-b'] as $id) {
            Event::assertDispatched(ApplicationChanged::class, fn ($event) => $event->tenantId === $id);
        }
    }

    public function test_http_fallback_covers_bulk_and_pivot_writes_with_one_event(): void
    {
        $request = Request::create('/api/rbac/roles/docente/permissions/test', 'PUT');
        $request->setUserResolver(fn () => new User(['role' => 'rector']));
        app(SynchronizeRealtimeChanges::class)->handle($request, fn () => response()->json(['granted' => false]));
        Event::assertDispatchedTimes(ApplicationChanged::class, 1);
        Event::assertDispatched(ApplicationChanged::class, fn ($event) => $event->tenantId === 'realtime-a' && $event->resources === ['rbac']);
    }

    public function test_assignment_writes_do_not_broadcast_an_all_resources_refresh(): void
    {
        $this->assertSame('schedule', RealtimeChanges::resourceForPath('/api/asignaciones/public-token'));
        $request = Request::create('/api/asignaciones/public-token', 'PUT');
        $request->setUserResolver(fn () => new User(['role' => 'rector']));
        app(SynchronizeRealtimeChanges::class)->handle($request, fn () => response()->json(['saved' => true]));
        Event::assertDispatchedTimes(ApplicationChanged::class, 1);
        Event::assertDispatched(ApplicationChanged::class, fn ($event) => $event->resources === ['schedule']);
    }

    public function test_reads_authentication_and_rejected_writes_do_not_trigger_refresh_loops(): void
    {
        foreach ([['GET', '/api/anos-lectivos', 200], ['POST', '/api/broadcasting/auth', 200],
            ['POST', '/api/tenant-broadcasting/auth', 200], ['POST', '/api/horarios', 422]] as [$method, $path, $status]) {
            $request = Request::create($path, $method);
            $request->setUserResolver(fn () => new User(['role' => 'rector']));
            app(SynchronizeRealtimeChanges::class)->handle($request, fn () => response()->json([], $status));
        }
        Event::assertNotDispatched(ApplicationChanged::class);
    }

    public function test_all_current_action_modules_have_an_invalidation_topic(): void
    {
        foreach (['anos-lectivos' => 'academic', 'periodos' => 'academic', 'estructura' => 'structure',
            'plan-estudios' => 'curriculum', 'horarios' => 'schedule', 'evaluacion' => 'evaluation',
            'siee' => 'academic-config', 'eventos' => 'events', 'onboarding' => 'institution',
            'usuarios' => 'users', 'rbac' => 'rbac', 'colegios' => 'schools', 'plans' => 'plans',
            'account' => 'account', 'storage' => 'storage', 'future-module' => 'all'] as $path => $resource) {
            $this->assertSame($resource, RealtimeChanges::resourceForPath('api/'.$path.'/123'));
        }
    }

    public function test_payload_contains_only_invalidation_topics(): void
    {
        $event = new ApplicationChanged('school-a', ['academic', 'schedule']);
        $this->assertSame(['resources' => ['academic', 'schedule']], $event->broadcastWith());
        $this->assertSame('private-tenant.'.TenantChannelName::tokenForId('school-a'), $event->broadcastOn()[0]->name);
        $this->assertSame('private-platform', (new ApplicationChanged(null, ['schools']))->broadcastOn()[0]->name);
    }

    public function test_channel_authentication_accepts_only_the_current_school_or_central_superadmin(): void
    {
        // Signing auth responses is local; no network or live database is used.
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app']);
        $broadcaster = Broadcast::connection('reverb');
        foreach (Broadcast::getChannels() as $name => $callback) {
            $broadcaster->channel($name, $callback, ['guards' => ['sanctum']]);
        }
        $user = new User(['role' => 'rector', 'status' => User::STATUS_ACTIVE]);
        foreach (['private-tenant.'.TenantChannelName::tokenForId('realtime-a') => true,
            'private-tenant.'.TenantChannelName::tokenForId('realtime-b') => false,
            'private-platform' => false] as $channel => $allowed) {
            $request = Request::create('/api/broadcasting/auth', 'POST', ['socket_id' => '123.456', 'channel_name' => $channel]);
            $request->setUserResolver(fn () => $user);
            try {
                $response = $broadcaster->auth($request);
                $this->assertTrue($allowed, 'Unexpected channel access: '.$channel);
                $this->assertNotEmpty($response);
            } catch (AccessDeniedHttpException) {
                $this->assertFalse($allowed, 'Expected channel access: '.$channel);
            }
        }
        tenancy()->end();
        $request = Request::create('/api/broadcasting/auth', 'POST', ['socket_id' => '123.456', 'channel_name' => 'private-platform']);
        $request->setUserResolver(fn () => new User(['role' => 'superadmin', 'status' => User::STATUS_ACTIVE]));
        $this->assertNotEmpty($broadcaster->auth($request));
    }
}
