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

final class AcademicCalendarOpaqueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_year_and_period_endpoints_hide_database_keys_in_opaque_contract(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('tenant');
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Calendario', 'slug' => 'calendario-'.uniqid(),
            'rector_email' => 'rector@calendario.test',
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
        $base = 'http://'.$school->slug.'.localhost/api';

        $this->getJson($base.'/anos-lectivos')->assertOk()
            ->assertJsonPath('data.0.url_token', $yearToken)
            ->assertJsonMissingPath('data.0.id');
        $this->getJson($base.'/anos-lectivos/'.$yearId)->assertNotFound();
        $this->getJson($base.'/anos-lectivos?opaque=0')->assertUnprocessable();
        $this->withHeader('X-Tenant', $school->id)
            ->getJson('http://localhost/api/anos-lectivos')->assertOk()
            ->assertJsonPath('data.0.url_token', $yearToken)
            ->assertJsonMissingPath('data.0.id');

        $this->getJson($base.'/anos-lectivos?opaque=1')->assertOk()
            ->assertJsonPath('data.0.url_token', $yearToken)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonStructure(['data' => [['legacy_url_token']]]);
        $this->getJson($base.'/anos-lectivos/'.$yearId.'?opaque=1')->assertNotFound();
        $this->getJson($base.'/anos-lectivos/'.$yearToken.'/estado-copia?opaque=1')
            ->assertOk()->assertJsonMissingPath('data.origen_id');
        $created = $this->postJson($base.'/anos-lectivos/'.$yearToken.'/periodos?opaque=1', [
            'nombre' => 'Primer período', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30',
            'peso' => 33,
        ])->assertCreated()->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.ano_lectivo_id')
            ->assertJsonPath('data.ano_lectivo_token', $yearToken)->json('data');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $created['url_token']);
        $this->getJson($base.'/anos-lectivos/'.$yearToken.'/periodos?opaque=1')
            ->assertOk()->assertJsonPath('data.0.url_token', $created['url_token'])
            ->assertJsonMissingPath('data.0.id');
        $this->putJson($base.'/periodos/'.$created['url_token'].'?opaque=1', [
            'nombre' => 'Primer período', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30',
            'peso' => 33,
        ])->assertOk()->assertJsonMissingPath('data.id');
        $this->putJson($base.'/periodos/1?opaque=1', [])->assertNotFound();
    }
}
