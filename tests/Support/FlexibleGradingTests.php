<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\Plan;
use App\Services\DuplicarAnoLectivoService;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Spatie\Permission\Models\Permission;

/** Uses the isolated academic fixture; never connects to a real school. */
trait FlexibleGradingTests
{
    private function flexibleFixture(bool $advanced = false): array
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $componentId = $activity->componente_id;
        $activity->delete();
        ComponenteEvaluacion::findOrFail($componentId)->delete();
        if ($advanced) {
            Plan::updateOrCreate(['key' => 'custom-assessment'], ['name' => 'Flexible', 'features' => ['academico', 'preinformes'], 'is_active' => true]);
            $this->school->update(['plan' => 'custom-assessment']);
        }
        foreach (['academico.preinformes.ver', 'academico.preinformes.gestionar'] as $key) {
            $this->rector->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->rector->createToken('web')->plainTextToken);
        $path = 'http://localhost/api/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $this->assignment->id).'/'.OpaqueUrlToken::for('periodo', $period->id);

        return [$period, $enrollment, $path];
    }

    private function configurePreinformes(Periodo $period, array $changes = [])
    {
        return $this->putJson('http://localhost/api/preinformes/'.OpaqueUrlToken::for('periodo', $period->id), [...[
            'version' => 0, 'usar_preinformes' => true, 'modo' => 'WEIGHTED_AVERAGE', 'fechas_estrictas' => false,
            'preinformes' => [['nombre' => 'Primer avance', 'peso' => '40'], ['nombre' => 'Segundo avance', 'peso' => '60']],
        ], ...$changes]);
    }

    public function test_flexible_direct_grades_do_not_require_preparation_and_normalize_decimals(): void
    {
        [$period, $enrollment, $path] = $this->flexibleFixture();
        // The old SIEE component mode must not constrain direct activities.
        $this->year->update(['siee' => [...$this->year->siee, 'modo_asignatura' => 'WEIGHTED_AVERAGE']]);
        foreach (['Quiz', 'Taller'] as $name) {
            $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0, 'nombre' => $name, 'fecha' => '2026-02-02'])->assertOk();
        }
        $sheet = $this->getJson($path.'?opaque=1')->assertOk()->json('data');
        $this->assertCount(1, $sheet['secciones']);
        $this->assertCount(2, $sheet['secciones'][0]['actividades']);
        $activities = $sheet['secciones'][0]['actividades'];
        $rows = array_map(fn ($a) => ['actividad_token' => $a['url_token'], 'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
            'valor' => '4,5', 'version' => 0, 'motivo' => 'Registro del período'], $activities);
        $saved = $this->putJson($path.'?opaque=1', ['notas' => $rows])->assertOk()->json('data');
        $this->assertSame('4.5', $saved['calificaciones'][0]['valor']);
        $this->assertSame('4.5', Calificacion::first()->valor);
        $this->assertSame('calculado', $saved['resultados'][0]['estado']);
        $this->assertSame('4.5', $saved['resultados'][0]['display_value']);
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($saved));
        $this->putJson($path.'?opaque=1', ['notas' => $rows])->assertConflict();
        $this->putJson($path.'/actividades', ['operacion' => 'eliminar', 'version' => 1,
            'componente_token' => $sheet['secciones'][0]['componente_token'], 'actividad_token' => $activities[0]['url_token']])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'calificacion', 'accion' => 'CREATE']);
    }

    public function test_gradebook_roster_provisional_grades_and_reason_follow_assignment_permissions(): void
    {
        [$period, $enrollment, $path] = $this->flexibleFixture();
        $enrollment->estudiante->update(['name' => 'Mariana Rodríguez Peña']);
        foreach (['Andrés Andrade Ortiz', 'Sofía Andrade Anzuate'] as $index => $name) {
            $student = \App\Models\User::create(['name' => $name, 'email' => 'roster'.$index.'@test.test',
                'password' => 'Student123456', 'role' => 'estudiante', 'status' => 'active']);
            \App\Models\Academico\Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }
        foreach (['Quiz', 'Taller'] as $name) {
            $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0,
                'nombre' => $name, 'fecha' => '2026-02-02'])->assertOk();
        }
        $sheet = $this->getJson($path.'?opaque=1')->assertOk()->json('data');
        $this->assertSame(['Andrade Anzuate Sofía', 'Andrade Ortiz Andrés', 'Rodríguez Peña Mariana'],
            array_column($sheet['matriculas'], 'nombre_lista'));
        $activity = $sheet['secciones'][0]['actividades'][0];
        $payload = ['notas' => [['actividad_token' => $activity['url_token'],
            'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id), 'valor' => '4.5', 'version' => 0]]];
        $this->putJson($path.'?opaque=1', $payload)->assertUnprocessable()->assertJsonValidationErrors('notas.0.motivo');
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        app('auth')->forgetGuards();
        $saved = $this->putJson($path.'?opaque=1', $payload)->assertOk()->json('data');
        $this->assertFalse($saved['requiere_motivo']);
        $graded = collect($saved['resultados'])->firstWhere('matricula_token', OpaqueUrlToken::for('matricula', $enrollment->id));
        $this->assertSame('pendiente', $graded['estado']);
        $this->assertSame('4.5', $graded['provisional']);
        $this->assertSame('pendiente', app(GradebookService::class)->subjectResult($this->assignment, $enrollment, $period,
            app(\App\Services\SieeConfiguration::class)->resolve($this->year))['estado']);
    }

    public function test_flexible_preinformes_weighted_and_equal_subtotals_match_reports_and_never_skip_empty_sections(): void
    {
        [$period, $enrollment, $path] = $this->flexibleFixture(true);
        $this->configurePreinformes($period)->assertOk();
        $sheet = $this->getJson($path.'?opaque=1')->assertOk()->json('data');
        $this->assertCount(2, $sheet['secciones']);
        $this->assertSame('pendiente', $sheet['resultados'][0]['estado']);
        foreach ($sheet['secciones'] as $i => $section) {
            $this->putJson($path.'/actividades', ['operacion' => 'crear', 'preinforme_token' => $section['preinforme_token'],
                'version' => 0, 'nombre' => 'Taller', 'fecha' => '2026-02-02'])->assertOk();
            $component = ComponenteEvaluacion::where('preinforme_id', Preinforme::orderBy('orden')->get()[$i]->id)->firstOrFail();
            $activity = ActividadEvaluacion::where('componente_id', $component->id)->firstOrFail();
            app(GradebookService::class)->saveGrades($this->rector, $this->assignment, $period->id, [[
                'matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => $i === 0 ? '5' : '3', 'version' => 0,
            ]]);
            if ($i === 0) {
                $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.estado', 'pendiente');
            }
        }
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.display_value', '3.8')
            ->assertJsonPath('data.resultados.0.secciones.0.display_value', '5');
        $report = app(GradebookService::class)->report($enrollment);
        $this->assertSame('3.8', $report['asignaturas'][0]['periodos'][0]['display_value']);
        $rows = Preinforme::orderBy('orden')->get()->map(fn ($pre) => ['url_token' => OpaqueUrlToken::for('preinforme', $pre->id), 'nombre' => $pre->nombre])->all();
        $this->configurePreinformes($period, ['version' => 1, 'modo' => 'SIMPLE_AVERAGE', 'preinformes' => $rows])->assertOk();
        $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.display_value', '4')
            ->assertJsonPath('data.resultados.0.raw_value', '4');
        // Renaming can swap two existing names without violating the old
        // component-name uniqueness constraint or changing the grade history.
        [$rows[0]['nombre'], $rows[1]['nombre']] = [$rows[1]['nombre'], $rows[0]['nombre']];
        $this->configurePreinformes($period, ['version' => 2, 'modo' => 'SIMPLE_AVERAGE', 'preinformes' => $rows])->assertOk();
        $this->assertSame(['Segundo avance', 'Primer avance'], ComponenteEvaluacion::orderBy('id')->pluck('nombre')->all());
        $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.display_value', '4');
        $this->configurePreinformes($period, ['version' => 3, 'usar_preinformes' => false])->assertUnprocessable();
        $this->assertSame(2, Preinforme::count());
    }

    public function test_flexible_activity_weights_versions_dates_and_parent_tokens_are_enforced(): void
    {
        [$period, , $path] = $this->flexibleFixture(true);
        $this->configurePreinformes($period, ['fechas_estrictas' => true, 'preinformes' => [
            ['nombre' => 'Avance', 'peso' => '100', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-02-28'],
        ]])->assertOk();
        $pre = OpaqueUrlToken::for('preinforme', Preinforme::firstOrFail()->id);
        $base = ['preinforme_token' => $pre, 'operacion' => 'modo', 'version' => 0, 'modo' => 'WEIGHTED_AVERAGE'];
        $this->putJson($path.'/actividades', $base)->assertOk();
        $this->putJson($path.'/actividades', $base)->assertConflict();
        $activity = ['preinforme_token' => $pre, 'operacion' => 'crear', 'version' => 0, 'nombre' => 'Quiz', 'fecha' => '2026-03-01', 'peso' => '40,5'];
        $this->putJson($path.'/actividades', $activity)->assertUnprocessable();
        $activity['fecha'] = '2026-02-05';
        $this->putJson($path.'/actividades', $activity)->assertOk();
        $this->assertSame('40.5', ActividadEvaluacion::first()->peso);
        $otherPeriod = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'P2', 'orden' => 2,
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-08-31', 'estado' => 'abierto',
            'configuracion_notas' => ['usar_preinformes' => true, 'modo' => 'SIMPLE_AVERAGE']]);
        $otherPre = Preinforme::create(['periodo_id' => $otherPeriod->id, 'nombre' => 'Otro', 'orden' => 1]);
        $this->putJson($path.'/actividades', [...$activity, 'preinforme_token' => OpaqueUrlToken::for('preinforme', $otherPre->id)])->assertNotFound();
        $period->update(['estado' => 'cerrado']);
        $this->putJson($path.'/actividades', [...$activity, 'nombre' => 'Quiz 2'])->assertUnprocessable();
        $this->configurePreinformes($period, ['version' => 1])->assertUnprocessable();
    }

    public function test_flexible_activity_percentages_are_teacher_defined_and_must_total_one_hundred(): void
    {
        [$period, $enrollment, $path] = $this->flexibleFixture();
        $this->putJson($path.'/actividades', ['operacion' => 'modo', 'version' => 0, 'modo' => 'WEIGHTED_AVERAGE'])->assertOk();
        foreach ([['Quiz', '30', '5'], ['Taller', '70', '3']] as [$name, $weight, $grade]) {
            $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0, 'nombre' => $name, 'peso' => $weight, 'fecha' => '2026-02-02'])->assertOk();
            $activity = ActividadEvaluacion::where('nombre', $name)->firstOrFail();
            app(GradebookService::class)->saveGrades($this->rector, $this->assignment, $period->id, [[
                'matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => $grade, 'version' => 0,
            ]]);
            if ($name === 'Quiz') {
                $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.estado', 'pendiente')
                    ->assertJsonPath('data.resultados.0.provisional', '5');
            }
        }
        $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.display_value', '3.6');
        $activity = ActividadEvaluacion::firstOrFail();
        $payload = ['operacion' => 'editar', 'version' => 1, 'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
            'nombre' => 'Quiz corregido', 'peso' => 40, 'fecha' => '2026-02-02'];
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $updated = $this->getJson($path.'?opaque=1')->assertJsonPath('data.resultados.0.estado', 'pendiente')->json('data');
        $this->assertSame(['Quiz corregido', 'Taller'], array_column($updated['secciones'][0]['actividades'], 'nombre'));
        $this->putJson($path.'/actividades', $payload)->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'actividad_evaluacion', 'accion' => 'UPDATE']);
    }

    public function test_flexible_plan_gate_and_effective_permissions_are_both_required(): void
    {
        [$period, , $path] = $this->flexibleFixture();
        $this->configurePreinformes($period)->assertForbidden();
        $this->assertSame(0, Preinforme::count());
        // Same teacher role: no create permission means no creating columns.
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        app('auth')->forgetGuards();
        $payload = ['operacion' => 'crear', 'version' => 0, 'nombre' => 'Taller', 'fecha' => '2026-02-05'];
        $this->putJson($path.'/actividades', $payload)->assertForbidden();
        $this->teacher->givePermissionTo(Permission::findOrCreate('notas.actividades.crear', 'web'));
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $created = ActividadEvaluacion::firstOrFail();
        $this->putJson($path.'/actividades', [...$payload, 'version' => 1,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $created->id)])->assertUnprocessable();
        $this->putJson($path.'/actividades', [...$payload, 'operacion' => 'editar', 'version' => 1,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $created->id)])->assertForbidden();
        $this->assignment->update(['docente_id' => null]);
        $this->putJson($path.'/actividades', [...$payload, 'nombre' => 'Otro'])->assertForbidden();
        // Explicitly delegated scope, not the role name, permits cross-assignment editing.
        $this->teacher->givePermissionTo(Permission::findOrCreate('notas.editar_no_dicta', 'web'));
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', [...$payload, 'nombre' => 'Otro'])->assertOk();
    }

    public function test_flexible_preinformes_copy_with_periods_without_grades_or_teacher_assignments(): void
    {
        [$period] = $this->flexibleFixture(true);
        $this->configurePreinformes($period)->assertOk();
        $copy = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'num_periodos' => 3,
        ], ['periodos' => true], $this->rector);
        $target = Periodo::where('ano_lectivo_id', $copy->id)->firstOrFail();
        $this->assertTrue($target->configuracion_notas['usar_preinformes']);
        $this->assertSame(2, Preinforme::where('periodo_id', $target->id)->count());
        $this->assertSame(0, ComponenteEvaluacion::where('periodo_id', $target->id)->count());
        $this->assertTrue(app(DuplicarAnoLectivoService::class)->copyStatus($copy)['reemplazable']);
    }

    public function test_flexible_config_validates_total_names_and_existing_data_before_mutation(): void
    {
        [$period, , $path] = $this->flexibleFixture(true);
        $this->configurePreinformes($period, ['preinformes' => [['nombre' => 'A', 'peso' => 20], ['nombre' => 'B', 'peso' => 20]]])->assertUnprocessable();
        $this->configurePreinformes($period, ['preinformes' => [['nombre' => 'A', 'peso' => 50], ['nombre' => ' A ', 'peso' => 50]]])->assertUnprocessable();
        $this->assertSame(0, Preinforme::count());
        $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0, 'nombre' => 'Directa', 'fecha' => '2026-02-05'])->assertOk();
        $this->configurePreinformes($period)->assertUnprocessable();
        $this->assertSame(0, Preinforme::count());
        $this->assertSame(1, ActividadEvaluacion::count());
    }

    public function test_flexible_tokens_from_another_tenant_and_numeric_selectors_are_rejected(): void
    {
        [$period, , $path] = $this->flexibleFixture(true);
        $other = \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => 'other-flexible-school', 'name' => 'Otro', 'slug' => 'other-flexible-school', 'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        $foreignPeriod = $other->run(fn () => OpaqueUrlToken::for('periodo', $period->id));
        $foreignYear = $other->run(fn () => OpaqueUrlToken::for('ano-lectivo', $this->year->id));
        $this->getJson('http://localhost/api/preinformes?ano_lectivo_token='.$foreignYear)->assertNotFound();
        $data = ['version' => 0, 'usar_preinformes' => false, 'modo' => 'SIMPLE_AVERAGE', 'fechas_estrictas' => false, 'preinformes' => []];
        $this->putJson('http://localhost/api/preinformes/'.$foreignPeriod, $data)->assertNotFound();
        $this->withHeader('X-Legacy-Academic-Ids', '0')->putJson('http://localhost/api/preinformes/'.$period->id, $data)->assertNotFound();
        $this->getJson('http://localhost/api/preinformes')->assertOk()->assertJsonMissingPath('data.anos.0.id')
            ->assertJsonMissingPath('data.periodos.0.id');
    }
}
