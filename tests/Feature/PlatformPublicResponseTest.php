<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPublicResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_legacy_user_endpoint_uses_public_account_payload(): void
    {
        $admin = $this->admin();
        $response = $this->withToken($admin->createToken('platform')->plainTextToken)
            ->getJson('http://localhost/api/user')->assertOk();

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $response->json('id'));
        $this->assertNotSame($admin->id, $response->json('id'));
        $response->assertJsonPath('email', $admin->email)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('temporary_password');
    }

    public function test_audit_catalog_and_entries_hide_internal_ids_even_inside_snapshots(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Auditoría', 'slug' => 'audit-'.uniqid(),
            'rector_email' => 'rector@audit.test',
        ]);
        PlatformAuditLog::create([
            'actor_id' => $admin->id, 'actor_email' => $admin->email,
            'accion' => 'SECURITY_TEST', 'recurso' => 'colegio',
            'recurso_id' => (string) $school->id, 'tenant_id' => (string) $school->id,
            'valor_nuevo' => ['id' => 42, 'actor_id' => 3, 'records' => [
                ['student_id' => 7, 'parentId' => 8, 'ids' => [9, 10], 'password' => 'hidden'],
            ]],
        ]);
        $token = $admin->createToken('platform')->plainTextToken;
        $catalog = $this->withToken($token)->getJson('http://localhost/api/platform/auditoria/colegios')
            ->assertOk()->json('data');
        $item = collect($catalog)->firstWhere('slug', $school->slug);
        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('id', $item);
        $this->assertArrayNotHasKey('parent_id', $item);

        $row = $this->getJson('http://localhost/api/platform/auditoria?accion=SECURITY_TEST')
            ->assertOk()->json('data.0');
        foreach (['id', 'actor_id', 'recurso_id', 'tenant_id', 'valor_nuevo.id',
            'valor_nuevo.actor_id', 'valor_nuevo.records.0.student_id',
            'valor_nuevo.records.0.parentId', 'valor_nuevo.records.0.ids.0'] as $path) {
            $value = data_get($row, $path);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $value, $path);
        }
        $this->assertSame('[REDACTED]', data_get($row, 'valor_nuevo.records.0.password'));

        $school->run(fn () => AuditLog::create([
            'actor_id' => 1, 'actor_email' => 'rector@audit.test',
            'accion' => 'SECURITY_TEST', 'recurso' => 'grupo', 'recurso_id' => '17',
            'valor_previo' => ['id' => 17, 'sede_id' => 2],
        ]));
        app('auth')->forgetGuards();
        $tenantRow = $this->getJson('http://localhost/api/platform/auditoria?colegio_slug='.$school->slug.'&accion=SECURITY_TEST')
            ->assertOk()->json('data.0');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $tenantRow['id']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $tenantRow['recurso_id']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $tenantRow['valor_previo']['sede_id']);
        $this->assertSame(17, $school->run(fn () => AuditLog::query()
            ->where('accion', 'SECURITY_TEST')->firstOrFail()->valor_previo['id']));
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@audit.test',
            'password' => 'Password!123', 'role' => 'superadmin', 'status' => User::STATUS_ACTIVE]);
    }
}
