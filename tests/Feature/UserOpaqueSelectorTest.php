<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserOpaqueSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_user_tokens_are_tenant_scoped_and_invalid_sede_never_falls_back_to_main_database(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        $schoolA = $this->school('user-a');
        $schoolB = $this->school('user-b');
        [$rectorToken, $userIdA, $tokenA] = $schoolA->run(function () {
            $rector = $this->readyRector();
            $target = $this->target('teacher-a@test.test');

            return [$rector->createToken('web')->plainTextToken, $target->id,
                OpaqueUrlToken::for('usuario', $target->id)];
        });
        [$userIdB, $tokenB] = $schoolB->run(function () {
            $target = $this->target('teacher-b@test.test');

            return [$target->id, OpaqueUrlToken::for('usuario', $target->id)];
        });
        $this->assertSame($userIdA, $userIdB);
        $this->assertNotSame($tokenA, $tokenB);
        $api = 'http://'.$schoolA->slug.'.localhost/api';
        $listed = $this->withToken($rectorToken)->getJson($api.'/usuarios')->assertOk()->json('data');
        $own = collect($listed)->firstWhere('email', 'teacher-a@test.test');
        $this->assertSame($tokenA, $own['id']);
        $this->assertSame($tokenA, $own['url_token']);
        $this->assertArrayNotHasKey('temporary_password', $own);

        $this->putJson($api.'/usuarios/'.$tokenB, ['name' => 'Ataque'])->assertNotFound();
        $this->getJson($api.'/usuarios/'.$tokenB.'/temporal-password')->assertNotFound();
        $this->postJson($api.'/usuarios/'.$tokenB.'/reset-password')->assertNotFound();
        $this->getJson($api.'/usuarios/'.$tokenA.'/temporal-password?sede_url_token=invalid')
            ->assertStatus(422);
        $this->getJson($api.'/usuarios/'.$tokenA.'/temporal-password')
            ->assertOk()->assertJsonPath('password', 'Temporary!123');
        $this->putJson($api.'/usuarios/'.$tokenA, ['name' => 'Docente actualizado'])
            ->assertOk()->assertJsonPath('data.name', 'Docente actualizado');
        $schoolB->run(fn () => $this->assertSame('Docente', User::findOrFail($userIdB)->name));
    }

    public function test_numeric_user_and_sede_selectors_are_rejected_outside_tests(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        $school = $this->school('user-numeric');
        [$rectorToken, $userId, $userToken] = $school->run(function () {
            $rector = $this->readyRector();
            $target = $this->target('teacher-numeric@test.test');

            return [$rector->createToken('web')->plainTextToken, $target->id,
                OpaqueUrlToken::for('usuario', $target->id)];
        });
        $api = 'http://'.$school->slug.'.localhost/api/usuarios';
        $this->withToken($rectorToken);

        $originalEnvironment = app()->environment();
        app()['env'] = 'production';
        try {
            $this->getJson($api.'/'.$userId.'/temporal-password')
                ->assertNotFound();
            $this->getJson($api.'/'.$userToken.'/temporal-password?sede_id=1')
                ->assertStatus(422);
            $this->getJson($api.'/'.$userToken.'/temporal-password')
                ->assertOk()->assertJsonPath('status', 'temporal');
        } finally {
            app()['env'] = $originalEnvironment;
        }
    }

    public function test_child_sede_tokens_route_operations_to_the_child_and_reset_revokes_its_sessions(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        $school = $this->school('user-parent');
        $child = $this->school('user-child');
        $child->update(['parent_id' => $school->id, 'tipo' => Tenant::TIPO_SEDE]);
        [$rectorToken, $sedeId, $sedeToken] = $school->run(function () use ($child) {
            $rector = $this->readyRector();
            $sede = Sede::create(['nombre' => 'Sede Norte', 'tenant_id' => $child->id,
                'estado' => Sede::ESTADO_ACTIVA]);

            return [$rector->createToken('web')->plainTextToken, $sede->id,
                OpaqueUrlToken::for('sede', $sede->id)];
        });
        $api = 'http://'.$school->slug.'.localhost/api';
        $created = $this->withToken($rectorToken)->postJson($api.'/usuarios', [
            'name' => 'Docente sede', 'email' => 'teacher-child@test.test', 'role' => 'docente',
            'sede_url_token' => $sedeToken,
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $created['url_token']);
        $this->assertSame($created['url_token'], $created['id']);
        $this->assertSame($sedeToken, $created['sede_url_token']);
        $this->assertSame($sedeToken, $created['sede_id']);
        $this->assertSame($sedeToken, $created['tenant_id']);
        $this->assertNotSame((string) $sedeId, $created['sede_id']);
        $childId = $child->run(function () {
            $target = User::where('email', 'teacher-child@test.test')->firstOrFail();
            $target->createToken('web');

            return $target->id;
        });
        $this->putJson($api.'/usuarios/'.$created['url_token'], [
            'name' => 'Docente sede actualizado', 'sede_url_token' => $sedeToken,
        ])->assertOk()->assertJsonPath('data.name', 'Docente sede actualizado');
        $this->postJson($api.'/usuarios/'.$created['url_token'].'/reset-password?sede_url_token='.$sedeToken)
            ->assertOk()->assertJsonPath('data.sede_url_token', $sedeToken);
        $child->run(function () use ($childId): void {
            $target = User::findOrFail($childId);
            $this->assertSame('Docente sede actualizado', $target->name);
            $this->assertSame(0, $target->tokens()->count());
        });
        $this->getJson($api.'/usuarios/'.$created['url_token'].'/temporal-password?sede_url_token='.$sedeToken)
            ->assertOk()->assertJsonPath('status', 'temporal');
    }

    public function test_user_list_paginates_across_parent_and_child_without_loading_all_child_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        $school = $this->school('paged-parent');
        $child = $this->school('paged-child');
        $child->update(['parent_id' => $school->id, 'tipo' => Tenant::TIPO_SEDE]);
        $token = $school->run(function () use ($child) {
            $rector = $this->readyRector();
            Sede::create(['nombre' => 'Sede Norte', 'tenant_id' => $child->id,
                'estado' => Sede::ESTADO_ACTIVA]);
            $this->target('parent-teacher@test.test');

            return $rector->createToken('web')->plainTextToken;
        });
        $child->run(function (): void {
            $this->target('child-first@test.test');
        });
        $api = 'http://'.$school->slug.'.localhost/api/usuarios';
        $small = $this->withToken($token)->getJson($api.'?per_page=5')->assertOk()->json();
        $this->assertLessThanOrEqual(20, $small['meta']['total']);
        $this->assertCount($small['meta']['total'], $small['data']);
        $this->assertSame(20, $small['meta']['per_page']);
        $this->assertSame(1, $small['meta']['last_page']);

        $child->run(function (): void {
            for ($n = 0; $n < 21; $n++) {
                $this->target('child-extra-'.$n.'@test.test');
            }
        });
        $first = $this->getJson($api.'?per_page=5&page=1')->assertOk()->json();
        $this->assertSame($small['meta']['total'] + 21, $first['meta']['total']);
        $this->assertCount(5, $first['data']);
        $this->assertSame(5, $first['meta']['per_page']);
        $emails = [];
        for ($page = 1; $page <= $first['meta']['last_page']; $page++) {
            $result = $this->getJson($api.'?per_page=5&page='.$page)->assertOk()->json();
            array_push($emails, ...array_column($result['data'], 'email'));
        }
        $this->assertCount($first['meta']['total'], $emails);
        $this->assertCount(count($emails), array_unique($emails));
    }

    private function school(string $slug): Tenant
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio '.$slug, 'slug' => $slug.'-'.uniqid(),
            'rector_email' => 'rector-'.$slug.'@test.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);

        return $school;
    }

    private function readyRector(): User
    {
        DatosInstitucionales::create([
            'nombre' => 'Colegio', 'nit' => '900123456-7', 'resolucion_men' => '123 de 2026',
            'direccion' => 'Calle 1', 'telefono' => '6011234567', 'correo' => 'colegio@test.test',
        ]);
        Storage::disk('tenant')->put(tenant()->id.'/branding/logo.png', 'logo');
        $rector = User::where('role', 'rector')->firstOrFail();
        $rector->update(['must_change_password' => false]);

        return $rector;
    }

    private function target(string $email): User
    {
        $target = User::create(['name' => 'Docente', 'email' => $email,
            'password' => 'Password!123', 'role' => 'docente', 'status' => User::STATUS_ACTIVE,
            'must_change_password' => true, 'temporary_password' => 'Temporary!123']);
        $target->assignRole('docente');

        return $target;
    }
}
