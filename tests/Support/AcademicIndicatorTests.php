<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Support\EvaluationOpaquePresenter;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

trait AcademicIndicatorTests
{
    private function indicatorApi(User $user): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($user->createToken('test-indicator')->plainTextToken);

        return 'http://localhost/api/indicadores';
    }

    private function indicatorContext(Periodo $period, ?Grupo $group = null): string
    {
        return '?opaque=1&grupo_token='.OpaqueUrlToken::for('grupo', ($group ?? $this->group)->id)
            .'&materia_token='.OpaqueUrlToken::for('materia', $this->subject->id)
            .'&periodo_token='.OpaqueUrlToken::for('periodo', $period->id);
    }

    private function indicatorCreateInput(?array $orders = null): array
    {
        return ['ano_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id),
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodos_orden' => $orders, 'texto' => 'Reconoce el entorno natural.', 'orden' => 1];
    }

    public function test_institutional_indicator_is_descriptive_and_period_scoped_without_an_assessment_scale(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $first = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $second = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Segundo', 'orden' => 2,
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-08-31', 'estado' => 'abierto']);
        $api = $this->indicatorApi($this->rector);
        $created = $this->postJson($api.'?opaque=1', $this->indicatorCreateInput([1]))
            ->assertCreated()->json('data');
        $this->getJson($api.$this->indicatorContext($first))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.')
            ->assertJsonMissingPath('data.politica')
            ->assertJsonMissingPath('data.indicadores.0.valoracion');
        $this->getJson($api.$this->indicatorContext($second))->assertOk()->assertJsonPath('data.total', 0);
        $this->putJson($api.'/'.$created['indicador_token'].'?opaque=1', [
            'version' => 1, 'texto' => 'Nueva redacción institucional.', 'orden' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($api.$this->indicatorContext($first))
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.');
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'?opaque=1', [
            'version' => 1, 'periodos_orden' => [1, 2], 'usar_revision_actual' => true,
        ])->assertOk();
        $this->getJson($api.$this->indicatorContext($second))
            ->assertJsonPath('data.indicadores.0.texto', 'Nueva redacción institucional.');
        $this->putJson($api.'/politica/'.OpaqueUrlToken::for('ano-lectivo', $this->year->id).'?opaque=1', [])
            ->assertNotFound();
        $this->assertFalse(Schema::hasTable('indicadores_configuracion'));
        $this->assertFalse(Schema::hasTable('indicadores_valoraciones'));
    }

    public function test_teacher_reads_assigned_objectives_and_edits_only_own_group_when_enabled(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->teacher->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.politica.gestionar', 'web'));
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $otherGroup = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'nombre' => 'B', 'estado' => 'activo']);
        $student = User::create(['name' => 'Estudiante de ensayo', 'email' => 'indicator-student@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $api = $this->indicatorApi($this->rector);
        $created = $this->postJson($api.'?opaque=1', $this->indicatorCreateInput())
            ->assertCreated()->json('data');
        $variantPath = $api.'/vinculos/'.$created['vinculo_token'].'/variante?opaque=1';
        $this->indicatorApi($this->teacher);
        $this->getJson($api.$this->indicatorContext($period))->assertOk()
            ->assertJsonPath('data.indicadores.0.fuente', 'institucional');
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'periodo_orden' => 1, 'texto' => 'Sin habilitar', 'version' => 0])->assertForbidden();
        $this->indicatorApi($this->rector);
        $this->putJson('http://localhost/api/boletines/politica?opaque=1', [
            'version' => 1, 'requiere_observacion_final' => false,
            'docente_edita_indicadores_grupo' => true,
        ])->assertOk();
        $this->indicatorApi($this->teacher);
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $otherGroup->id),
            'periodo_orden' => 1, 'texto' => 'Intento ajeno', 'version' => 0])->assertForbidden();
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'periodo_orden' => 1, 'texto' => 'Meta del grupo A', 'version' => 0])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson($api.$this->indicatorContext($period))
            ->assertJsonPath('data.indicadores.0.texto', 'Meta del grupo A');
        $this->getJson($api.$this->indicatorContext($period, $otherGroup))->assertForbidden();
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'/valoraciones/'
            .OpaqueUrlToken::for('matricula', $enrollment->id).'?opaque=1', [])->assertNotFound();
        $scale = \App\Models\Academico\EscalaValorativa::create([
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica',
            'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1,
        ]);
        $method = \App\Models\Academico\MetodoAprobacion::create([
            'ano_lectivo_id' => $this->year->id, 'calculo_nota' => 'promedio_simple',
            'nota_minima' => '3', 'ambito' => 'materia',
        ]);
        $this->year->update(['siee' => [...\App\Services\SieeConfiguration::DEFAULTS,
            'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $preview = EvaluationOpaquePresenter::report(
            app(GradebookService::class)->report($enrollment->load('estudiante', 'grupo.grado')));
        $this->assertSame('Meta del grupo A', $preview['indicadores'][0]['texto']);
        $this->assertArrayNotHasKey('valoracion', $preview['indicadores'][0]);
        $this->assertArrayNotHasKey('alcanzado', $preview['indicadores'][0]);
        $period->update(['estado' => 'cerrado']);
        $this->getJson($api.$this->indicatorContext($period))->assertOk();
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'periodo_orden' => 1, 'texto' => 'Cambio tardío', 'version' => 1])->assertUnprocessable();
        $this->assertSame(0, DB::table('indicadores_variantes')->where('texto', 'Cambio tardío')->count());
    }
}
