<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaReady;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\CorreoConfiguracion;
use App\Models\SchoolMailChangeRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SchoolMail;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\InitializingTenancy;
use Tests\TestCase;

class SchoolMailApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $school;

    private User $admin;

    private string $rectorToken;

    private string $rectorId;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::create(['name' => 'Admin prueba', 'email' => 'mail-admin@example.test', 'password' => 'TestPassword123!', 'role' => 'superadmin', 'status' => 'active']);
        $this->school = app(TenantProvisioner::class)->provision(['name' => 'Correo pruebas', 'slug' => 'mail-'.uniqid(), 'rector_email' => 'rector@example.test'])['tenant'];
        $this->school->update(['status' => 'active']);
        $this->school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $this->rectorId = (string) $rector->id;
            $this->rectorToken = $rector->createToken('test')->plainTextToken;
        });
        $this->base = 'http://'.$this->school->slug.'.localhost/api/correo-institucional';
        $this->withoutMiddleware([EnsureOnboardingComplete::class, EnsureMfaReady::class, ThrottleRequests::class]);
        Queue::fake();
        $this->rector();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    private function rector(?string $token = null): void
    {
        tenancy()->end();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($token ?? $this->rectorToken);
    }

    private function platform(): void
    {
        tenancy()->end();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->admin->createToken('platform')->plainTextToken);
    }

    private function payload(): array
    {
        return ['email' => 'school@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcdefghijklmnop'];
    }

    private function saved(): void
    {
        $this->school->run(fn () => CorreoConfiguracion::create(['key' => 'gmail', ...$this->payload(), 'verificado_en' => now()]));
    }

    private function request(string $action = 'editar'): string
    {
        $this->rector();

        return $this->postJson($this->base.'/solicitudes', ['accion' => $action, 'motivo' => 'Actualizar el remitente institucional.'])
            ->assertOk()->assertJsonPath('puede_editar', false)->json('solicitud.url_token');
    }

    private function decide(string $token, string $decision = 'aprobar'): void
    {
        $this->platform();
        $this->postJson('http://localhost/api/platform/correo-solicitudes/'.$token.'/resolver',
            ['decision' => $decision, 'observacion' => 'Decisión de prueba aislada.'])->assertOk();
        $this->rector();
    }

    public function test_first_connection_locks_and_platform_approves_one_change_only(): void
    {
        $this->partialMock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('test')->twice());
        $this->putJson($this->base, $this->payload())->assertOk()->assertJsonPath('puede_editar', false);
        $this->rector();
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $this->deleteJson($this->base)->assertForbidden();
        $token = $this->request();
        $this->assertSame($token, $this->request());
        $this->assertSame(1, SchoolMailChangeRequest::count());
        $this->platform();
        $this->getJson('http://localhost/api/platform/correo-solicitudes/resumen')->assertOk()->assertJsonPath('pendientes', 1);
        $list = $this->getJson('http://localhost/api/platform/correo-solicitudes')->assertOk();
        foreach (['id', 'tenant_id', 'requester_id', 'reviewer_id', 'revision', 'app_password'] as $field) {
            $list->assertJsonMissingPath('data.0.'.$field);
        }
        $list->assertJsonPath('data.0.colegio.slug', $this->school->slug);
        $this->decide($token);
        $this->getJson($this->base)->assertOk()->assertJsonPath('puede_editar', true)->assertJsonPath('puede_desconectar', false);
        $this->putJson($this->base, $this->payload())->assertOk()->assertJsonPath('puede_editar', false)->assertJsonPath('solicitud.estado', 'utilizada');
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $this->platform();
        $this->postJson('http://localhost/api/platform/correo-solicitudes/'.$token.'/resolver', ['decision' => 'aprobar'])->assertConflict();
        $this->getJson('http://localhost/api/platform/correo-solicitudes/resumen')->assertOk()->assertJsonPath('pendientes', 0);
    }

    public function test_expired_or_rejected_grant_does_not_unlock_and_requires_new_request(): void
    {
        $this->saved();
        $token = $this->request();
        $this->platform();
        $this->postJson('http://localhost/api/platform/correo-solicitudes/'.$token.'/resolver', ['decision' => 'rechazar'])->assertUnprocessable();
        $this->decide($token, 'rechazar');
        $this->getJson($this->base)->assertOk()->assertJsonPath('solicitud.estado', 'rechazada')->assertJsonPath('puede_editar', false);
        $second = $this->request();
        $this->assertNotSame($token, $second);
        $this->decide($second);
        SchoolMailChangeRequest::where('url_token', $second)->update(['expires_at' => now()->subMinute()]);
        $this->getJson($this->base)->assertOk()->assertJsonPath('solicitud.estado', 'vencida')->assertJsonPath('puede_editar', false);
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $this->assertNotSame($second, $this->request());
    }

    public function test_disconnect_requires_its_own_grant_and_cannot_reset_first_setup(): void
    {
        $this->saved();
        $token = $this->request();
        $this->decide($token);
        $this->deleteJson($this->base)->assertForbidden();
        SchoolMailChangeRequest::where('url_token', $token)->update(['expires_at' => now()->subMinute()]);
        $disconnect = $this->request('desconectar');
        $this->decide($disconnect);
        $this->deleteJson($this->base)->assertOk()->assertJsonPath('configurado', false)
            ->assertJsonPath('puede_editar', false)->assertJsonPath('requiere_autorizacion', true);
        $this->assertNull($this->school->run(fn () => DB::table('correo_configuracion')->value('app_password')));
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $reconnect = $this->request();
        $this->decide($reconnect);
        $this->partialMock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('test')->once());
        $this->putJson($this->base, $this->payload())->assertOk()->assertJsonPath('configurado', true)->assertJsonPath('puede_editar', false);
    }

    public function test_permission_alone_other_actor_and_other_school_cannot_use_approval(): void
    {
        $this->saved();
        $token = $this->request();
        $this->decide($token);
        $other = $this->school->run(function () {
            $u = User::create(['name' => 'Docente', 'email' => 'teacher@example.test', 'password' => 'TestPassword123!', 'role' => 'docente', 'status' => 'active']);
            $u->givePermissionTo('config.correo');

            return $u->createToken('test')->plainTextToken;
        });
        $this->rector($other);
        $this->getJson($this->base)->assertForbidden();
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $this->postJson($this->base.'/solicitudes', ['accion' => 'editar', 'motivo' => 'No debe permitir delegación.'])->assertForbidden();
        $this->rector($other);
        $this->getJson('http://localhost/api/platform/correo-solicitudes')->assertUnauthorized();
        $this->rector();
        $grant = SchoolMailChangeRequest::where('url_token', $token)->firstOrFail();
        $grant->update(['requester_id' => 'different-actor']);
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $grant->update(['requester_id' => $this->rectorId, 'tenant_id' => 'another-school']);
        $this->putJson($this->base, $this->payload())->assertForbidden();
        $this->platform();
        $this->postJson('http://localhost/api/platform/correo-solicitudes/1/resolver', ['decision' => 'aprobar'])->assertNotFound();
    }

    public function test_failed_test_preserves_grant_but_success_rotation_defeats_stale_central_state(): void
    {
        $this->saved();
        $token = $this->request();
        $this->decide($token);
        $this->partialMock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('test')->once()->andThrow(new \RuntimeException('Prueba falló.')));
        $before = $this->school->run(fn () => DB::table('correo_configuracion')->first());
        $this->putJson($this->base, $this->payload())->assertUnprocessable();
        $this->assertSame($before->app_password, $this->school->run(fn () => DB::table('correo_configuracion')->value('app_password')));
        $this->getJson($this->base)->assertOk()->assertJsonPath('puede_editar', true);
        $this->partialMock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('test')->once());
        $this->putJson($this->base, $this->payload())->assertOk();
        SchoolMailChangeRequest::where('url_token', $token)->update(['estado' => 'aprobada']);
        $this->getJson($this->base)->assertOk()->assertJsonPath('puede_editar', false)->assertJsonPath('solicitud.estado', 'utilizada');
        $this->putJson($this->base, $this->payload())->assertForbidden();
    }

    public function test_first_setup_never_upserts_over_a_competing_insert(): void
    {
        $this->partialMock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('test')->once());
        $injected = false;
        CorreoConfiguracion::creating(function ($setting) use (&$injected) {
            if ($injected) {
                return;
            }
            $injected = true;
            // Force a primary-key collision precisely after the absent-row check.
            DB::table('correo_configuracion')->insert(['key' => 'gmail', 'email' => 'competing@example.test',
                'nombre' => 'Conexión concurrente', 'app_password' => null, 'verificado_en' => now(), 'revision' => str_repeat('z', 32)]);
        });
        $this->putJson($this->base, $this->payload())->assertConflict();
        $this->assertTrue($injected);
    }

    public function test_unavailable_school_does_not_break_the_rest_of_the_platform_inbox(): void
    {
        $this->saved();
        $first = $this->request();
        $this->platform();
        $second = app(TenantProvisioner::class)->provision(['name' => 'Segundo colegio', 'slug' => 'mail-second-'.uniqid(), 'rector_email' => 'second@example.test'])['tenant'];
        $second->update(['status' => 'active']);
        $revision = $second->run(fn () => CorreoConfiguracion::create(['key' => 'gmail', ...$this->payload(), 'verificado_en' => now()])->revision);
        SchoolMailChangeRequest::create(['url_token' => str_repeat('s', 24), 'tenant_id' => $second->id,
            'requester_id' => 'second-rector', 'requester_name' => 'Rector segundo', 'revision' => $revision,
            'accion' => 'editar', 'motivo' => 'Solicitud del segundo colegio.', 'estado' => 'pendiente']);
        $this->school->run(fn () => Schema::drop('correo_configuracion'));
        $this->platform();
        $data = $this->getJson('http://localhost/api/platform/correo-solicitudes')->assertOk()->json('data');
        $this->assertFalse(collect($data)->firstWhere('url_token', $first)['disponible']);
        $this->assertTrue(collect($data)->firstWhere('url_token', str_repeat('s', 24))['disponible']);
        $this->postJson('http://localhost/api/platform/correo-solicitudes/'.$first.'/resolver', ['decision' => 'aprobar'])->assertStatus(503);
        $this->assertSame('pendiente', SchoolMailChangeRequest::where('url_token', $first)->value('estado'));
    }

    public function test_platform_inspection_never_initializes_tenant_or_changes_central_context(): void
    {
        $this->saved();
        $token = $this->request();
        $this->platform();
        $default = DB::getDefaultConnection();
        $cache = app('cache');
        $paths = config('filesystems.disks');
        Event::listen(InitializingTenancy::class, fn () => throw new \RuntimeException('Platform must not bootstrap tenants.'));
        $this->getJson('http://localhost/api/platform/correo-solicitudes')->assertOk()
            ->assertJsonPath('data.0.disponible', true)->assertJsonPath('data.0.estado', 'pendiente');
        $this->postJson('http://localhost/api/platform/correo-solicitudes/'.$token.'/resolver', ['decision' => 'aprobar'])->assertOk();
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenant());
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($cache, app('cache'));
        $this->assertSame($paths, config('filesystems.disks'));
        $this->assertArrayNotHasKey('school-mail-inspection-'.$this->school->getKey(), DB::getConnections());
    }
}
