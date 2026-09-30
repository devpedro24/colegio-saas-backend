<?php

namespace Tests\Feature;

use App\Events\ApplicationChanged;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        parent::tearDown();
    }

    public function test_private_channel_routes_work_during_onboarding_and_with_a_tenant_header(): void
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant] = app(TenantProvisioner::class)->provision([
            'name' => 'Realtime test', 'slug' => 'realtime-'.uniqid(), 'rector_email' => 'rector@realtime.test',
        ]);
        $token = $tenant->run(fn () => User::where('email', 'rector@realtime.test')->firstOrFail()->createToken('web')->plainTextToken);
        Event::fake([ApplicationChanged::class]);
        $broadcaster = Broadcast::connection('reverb');
        foreach (Broadcast::getChannels() as $name => $callback) {
            $broadcaster->channel($name, $callback, ['guards' => ['sanctum']]);
        }
        config(['broadcasting.default' => 'reverb']);
        $channel = ['channel_name' => 'private-tenant.'.$tenant->id, 'socket_id' => '123.456'];
        $this->withToken($token)->postJson('http://'.$tenant->slug.'.localhost/api/broadcasting/auth', $channel)
            ->assertOk()->assertJsonStructure(['auth']);
        $this->getJson('http://'.$tenant->slug.'.localhost/api/onboarding/status')->assertOk();
        Event::assertNotDispatched(ApplicationChanged::class);
        $this->postJson('http://'.$tenant->slug.'.localhost/api/broadcasting/auth', [...$channel, 'channel_name' => 'private-tenant.another'])
            ->assertForbidden();
        tenancy()->end();
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $tenant->id)->postJson('http://localhost/api/tenant-broadcasting/auth', $channel)
            ->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('http://localhost/api/tenant-broadcasting/auth', [...$channel, 'channel_name' => 'private-platform'])
            ->assertForbidden();
    }

    public function test_catalog_changes_queue_permission_updates_for_existing_schools(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SynchronizeTenantPermissions::class]);
        Event::fake([ApplicationChanged::class]);
        \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => 'realtime-school', 'name' => 'Realtime', 'slug' => 'realtime', 'plan' => 'esencial',
            'tipo' => 'colegio', 'status' => 'active',
        ]));
        \App\Events\PlatformDataChanged::dispatch('rbac', 'updated');
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SynchronizeTenantPermissions::class,
            fn ($job) => $job->tenantId === 'realtime-school');
    }
}
