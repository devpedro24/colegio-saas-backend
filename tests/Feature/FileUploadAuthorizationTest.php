<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\DatosInstitucionales;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        parent::tearDown();
    }

    public function test_generic_upload_requires_permission_and_is_rate_limited_per_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Upload', 'slug' => 'upload-'.uniqid(),
            'rector_email' => 'rector-upload@test.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$rectorToken, $studentToken] = $school->run(function () {
            DatosInstitucionales::create([
                'nombre' => 'Colegio', 'nit' => '900123456-7', 'resolucion_men' => '123 de 2026',
                'direccion' => 'Calle 1', 'telefono' => '6011234567', 'correo' => 'colegio@test.test',
            ]);
            Storage::disk('tenant')->put(tenant()->id.'/branding/logo.png', 'logo');
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $student = User::create(['name' => 'Estudiante', 'email' => 'student-upload@test.test',
                'password' => 'Password!123', 'role' => 'estudiante', 'status' => User::STATUS_ACTIVE,
                'must_change_password' => false]);
            $student->assignRole('estudiante');

            return [$rector->createToken('web')->plainTextToken, $student->createToken('web')->plainTextToken];
        });
        $api = 'http://'.$school->slug.'.localhost/api/archivos';

        $status = $this->withToken($studentToken)
            ->getJson('http://'.$school->slug.'.localhost/api/onboarding/status')
            ->assertOk()->json();
        $this->assertArrayNotHasKey('id', $status['institution']);
        $this->assertArrayNotHasKey('logo_principal', $status['institution']);
        $account = $this->getJson('http://'.$school->slug.'.localhost/api/me')
            ->assertOk()->json('user');
        $this->assertArrayNotHasKey('tenant_id', $account);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $account['id']);

        $this->withToken($studentToken)->postJson($api, [])->assertForbidden();
        $this->withToken($rectorToken);
        app('auth')->forgetGuards();
        $this->postJson($api, ['folder' => 'C:\otro'])->assertUnprocessable()
            ->assertJsonValidationErrors('folder');
        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->postJson($api, [])->assertUnprocessable();
        }
        $this->postJson($api, [])->assertTooManyRequests();
    }
}
