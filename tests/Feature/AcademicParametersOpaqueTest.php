<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcademicParametersOpaqueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_parameter_api_uses_only_tenant_scoped_public_selectors(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Parámetros', 'slug' => 'parametros-'.uniqid(),
            'rector_email' => 'rector@parametros.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $yearId, $yearToken] = $school->run(function (): array {
            DatosInstitucionales::create([
                'nombre' => 'Colegio', 'nit' => '900123456-7', 'resolucion_men' => '123 de 2026',
                'direccion' => 'Calle 1', 'telefono' => '6011234567', 'correo' => 'colegio@test.test',
            ]);
            Storage::disk('tenant')->put(tenant()->id.'/branding/logo.png', 'logo');
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create([
                'nombre' => '2026', 'tipo_calendario' => 'A',
                'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
                'num_periodos' => 3, 'estado' => AnoLectivo::ESTADO_PLANIFICADO,
            ]);

            return [$rector->createToken('web')->plainTextToken, $year->id,
                OpaqueUrlToken::for('ano-lectivo', $year->id)];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api/config';
        $this->getJson($base.'/datos-institucionales')->assertOk()
            ->assertJsonPath('data.nombre', 'Colegio')->assertJsonMissingPath('data.id');

        foreach ([
            ['escalas', ['nombre' => 'Numérica', 'nivel_educativo' => 'primaria',
                'tipo' => 'numerica', 'valor_min' => 1, 'valor_max' => 5, 'decimales' => 1]],
            ['metodos-aprobacion', ['calculo_nota' => 'promedio_simple',
                'nota_minima' => 3, 'ambito' => 'materia']],
            ['modelos-pedagogicos', ['nivel_educativo' => 'primaria',
                'docente_unico' => true, 'salon_fijo' => true, 'tiene_director_grupo' => true]],
        ] as [$path, $fields]) {
            $created = $this->postJson($base.'/'.$path.'?opaque=1', [
                ...$fields, 'ano_lectivo_token' => $yearToken,
            ])->assertCreated()->assertJsonMissingPath('data.id')
                ->assertJsonMissingPath('data.ano_lectivo_id')
                ->assertJsonPath('data.ano_lectivo_token', $yearToken)->json('data');
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $created['url_token']);
            $this->getJson($base.'/'.$path.'?opaque=1&ano_lectivo_token='.$yearToken)
                ->assertOk()->assertJsonPath('data.0.url_token', $created['url_token'])
                ->assertJsonMissingPath('data.0.id');
            $this->putJson($base.'/'.$path.'/'.$created['url_token'].'?opaque=1', [
                ...$fields, 'ano_lectivo_token' => $yearToken,
            ])->assertOk()->assertJsonPath('data.url_token', $created['url_token'])
                ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.ano_lectivo_id');
            $this->putJson($base.'/'.$path.'/'.$yearId.'?opaque=1', [
                ...$fields, 'ano_lectivo_token' => $yearToken,
            ])->assertNotFound();
            $this->postJson($base.'/'.$path.'?opaque=1', [
                ...$fields, 'ano_lectivo_id' => $yearId,
            ])->assertStatus(422);
        }
    }
}
