<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait BulletinObservationTests
{
    private function bulletinApi(User $user): string
    {
        // This legacy fixture creates roles after tenant migrations; production
        // roles already exist when the permission migration grants defaults.
        if ($user->hasRole('rector')) {
            foreach (['boletines.politica.gestionar', 'boletines.observaciones.gestionar',
                'boletines.observaciones.aprobar'] as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        }
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($user->createToken('test-bulletin')->plainTextToken);

        return 'http://localhost/api/boletines';
    }

    public function test_bulletin_policy_is_school_configurable_with_version_and_rector_permission(): void
    {
        $api = $this->bulletinApi($this->teacher);
        $this->getJson($api.'/politica')->assertForbidden();
        $this->putJson($api.'/politica', ['version' => 1,
            'requiere_observacion_final' => true, 'docente_edita_indicadores_grupo' => true])->assertForbidden();

        $this->bulletinApi($this->rector);
        $this->getJson($api.'/politica')->assertOk()
            ->assertJsonPath('data.requiere_observacion_final', false)
            ->assertJsonPath('data.docente_edita_indicadores_grupo', false);
        $this->putJson($api.'/politica', ['version' => 1,
            'requiere_observacion_final' => true, 'docente_edita_indicadores_grupo' => true])
            ->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.requiere_observacion_final', true);
        $this->putJson($api.'/politica', ['version' => 1,
            'requiere_observacion_final' => false, 'docente_edita_indicadores_grupo' => false])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'configuracion_boletines', 'accion' => 'UPDATE']);
    }

    public function test_bulletin_observations_are_individual_period_scoped_versioned_and_require_approval(): void
    {
        Role::findOrCreate('estudiante', 'web');
        $student = User::create(['name' => 'Estudiante sintético', 'email' => 'bulletin-student@test.test',
            'password' => 'TestingPassword123!', 'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primer período',
            'orden' => 1, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $otherYear = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 1, 'estado' => 'planificado']);
        $otherPeriod = Periodo::create(['ano_lectivo_id' => $otherYear->id, 'nombre' => 'Otro año',
            'orden' => 1, 'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'estado' => 'planificado']);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $api = $this->bulletinApi($this->rector).'/observaciones/'.$token;
        $this->getJson($api)->assertOk()->assertJsonPath('data.periodos.0.observacion', null);
        $payload = ['contexto' => 'periodo', 'periodo_token' => $periodToken,
            'contenido' => 'Avanzó en lectura y trabajo colaborativo.', 'version' => 0];
        $this->putJson($api, [...$payload, 'periodo_token' => OpaqueUrlToken::for('periodo', $otherPeriod->id)])
            ->assertUnprocessable();
        $this->putJson($api, $payload)->assertOk()->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.autor', 'Rector')->assertDontSee('matricula_id');
        $this->putJson($api, $payload)->assertConflict();
        $this->putJson($api, [...$payload, 'version' => 1])->assertOk()->assertJsonPath('data.autor', 'Rector');
        $this->postJson($api.'/aprobar', ['contexto' => 'periodo', 'periodo_token' => $periodToken, 'version' => 1])
            ->assertOk()->assertJsonPath('data.estado', 'aprobada')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.aprobador', 'Rector');
        $this->putJson($api, [...$payload, 'contenido' => 'Versión revisada.', 'version' => 2])
            ->assertOk()->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.version', 3);
        $this->assertSame(3, DB::table('observaciones_boletin_revisiones')->count());
        $this->getJson($api)->assertJsonPath('data.periodos.0.observacion.contenido', 'Versión revisada.');
        $this->putJson($api, ['contexto' => 'final', 'contenido' => 'Cierre anual individual.', 'version' => 0])
            ->assertOk()->assertJsonPath('data.contexto', 'final')->assertJsonPath('data.periodo_token', null);
        $this->getJson($api)->assertJsonPath('data.final.contenido', 'Cierre anual individual.');
        $this->year->update(['estado' => 'cerrado']);
        $this->putJson($api, ['contexto' => 'final', 'contenido' => 'Cambio tardío.', 'version' => 1])
            ->assertUnprocessable();

        $this->bulletinApi($this->teacher);
        $this->getJson($api)->assertForbidden();
        $this->putJson($api, [...$payload, 'version' => 3])->assertForbidden();
        $this->postJson($api.'/aprobar', ['contexto' => 'periodo', 'periodo_token' => $periodToken, 'version' => 3])
            ->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'observacion_boletin', 'accion' => 'APPROVE']);
    }
}
