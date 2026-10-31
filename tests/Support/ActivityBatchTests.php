<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Events\ApplicationChanged;
use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Preinforme;
use App\Models\Tenant;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;

/** HTTP regressions on the isolated academic database, including concurrent editor snapshots. */
trait ActivityBatchTests
{
    private function weightedActivityFixture(bool $advanced = false): array
    {
        [$period, $enrollment, $path] = $this->flexibleFixture($advanced);
        $selector = [];
        if ($advanced) {
            $this->configurePreinformes($period)->assertOk();
            $selector = ['preinforme_token' => OpaqueUrlToken::for('preinforme', Preinforme::orderBy('orden')->firstOrFail()->id)];
        }
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'modo', 'version' => 0, 'modo' => 'WEIGHTED_AVERAGE'])->assertOk();
        foreach (['A' => '20', 'B' => '80'] as $name => $weight) {
            $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'crear', 'version' => 0,
                'nombre' => $name, 'fecha' => '2026-02-05', 'peso' => $weight])->assertOk();
        }
        $component = ActividadEvaluacion::where('nombre', 'A')->firstOrFail()->componente;

        return [$period, $enrollment, $path, $component];
    }

    private function activityBatchPayload(ComponenteEvaluacion $component): array
    {
        $component->refresh();

        return ['operacion' => 'editar_lote', 'componente_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
            'version' => $component->version,
            'actividades' => $component->actividades()->orderBy('id')->get()->map(fn ($activity) => [
                'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'version' => $activity->version,
                'nombre' => $activity->nombre, 'fecha' => $activity->fecha,
                ...($component->modo === 'WEIGHTED_AVERAGE' ? ['peso' => $activity->peso] : []),
            ])->all()];
    }

    private function changeActivityMode(string $path, ComponenteEvaluacion $component, string $mode): void
    {
        $component->refresh();
        $this->putJson($path.'/actividades', ['operacion' => 'modo', 'version' => $component->version,
            'componente_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id), 'modo' => $mode])->assertOk();
    }

    private function activityRowsSnapshot(): array
    {
        return DB::table('actividades_evaluacion')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function activityAuditCount(): int
    {
        return DB::table('audit_logs')->where('recurso', 'actividad_evaluacion')->count();
    }

    public function test_activity_batch_reweights_atomically_and_preserves_exact_grades_audit_and_invalidation(): void
    {
        [$period, $enrollment, $path, $component] = $this->weightedActivityFixture();
        $activities = $component->actividades()->orderBy('id')->get();
        $book = app(GradebookService::class);
        foreach ([['3.4', '3.3'], ['3.8', '4.1']] as $iteration => $values) {
            $book->saveGrades($this->rector, $this->assignment, $period->id, $activities->map(fn ($activity, $index) => [
                'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => $values[$index], 'version' => $iteration,
            ])->all());
            if ($iteration === 0) {
                $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.raw_value', '3.32')
                    ->assertJsonPath('data.resultados.0.exact_value', '83/25')->assertJsonPath('data.resultados.0.display_value', '3.3');
                $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
                $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
                $this->assertSame(['20', '80'], $component->actividades()->pluck('peso')->all());
            }
        }
        $grades = Calificacion::orderBy('id')->get()->toArray();
        $gradeAudits = DB::table('audit_logs')->where('recurso', 'calificacion')->count();
        $audits = $this->activityAuditCount();
        $payload = $this->activityBatchPayload($component);
        $payload['actividades'][0]['peso'] = '90';
        $payload['actividades'][1]['peso'] = 10;
        // Names can also be swapped in the same final set.
        $payload['actividades'][0]['nombre'] = 'B';
        $payload['actividades'][1]['nombre'] = 'A';
        Event::fake([ApplicationChanged::class]);
        $saved = $this->putJson($path.'/actividades', $payload)->assertOk()->assertExactJson(['data' => ['guardado' => true]]);
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $saved->getContent());
        Event::assertDispatched(ApplicationChanged::class, fn ($event) => $event->tenantId === $this->school->id && in_array('grades', $event->resources, true));
        $this->assertSame($audits + 2, $this->activityAuditCount());
        $audit = DB::table('audit_logs')->where('recurso', 'actividad_evaluacion')->where('accion', 'UPDATE')->orderByDesc('id')->first();
        $this->assertSame('80', json_decode($audit->valor_previo, true)['peso']);
        $this->assertSame('10', json_decode($audit->valor_nuevo, true)['peso']);
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.raw_value', '3.83')
            ->assertJsonPath('data.resultados.0.exact_value', '383/100')->assertJsonPath('data.resultados.0.display_value', '3.8');
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.raw_value', '3.95')
            ->assertJsonPath('data.resultados.0.display_value', '3.9');
        $simple = $this->activityBatchPayload($component);
        $this->assertArrayNotHasKey('peso', $simple['actividades'][0]);
        $simple['actividades'][0]['nombre'] = 'Edición simple';
        $this->putJson($path.'/actividades', $simple)->assertOk();
        $activity = $component->actividades()->first();
        $this->putJson($path.'/actividades', ['operacion' => 'editar', 'version' => $activity->version,
            'componente_token' => $simple['componente_token'], 'actividad_token' => $simple['actividades'][0]['actividad_token'],
            'nombre' => $activity->nombre, 'fecha' => $activity->fecha, 'peso' => null])->assertOk();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame(['90', '10'], $component->actividades()->pluck('peso')->all());
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.raw_value', '3.83');
        $this->assertSame($grades, Calificacion::orderBy('id')->get()->toArray());
        $this->assertSame($gradeAudits, DB::table('audit_logs')->where('recurso', 'calificacion')->count());
    }

    public function test_activity_batch_visual_equivalents_use_saved_weights_and_simple_average(): void
    {
        [$period, $enrollment, $path, $component] = $this->weightedActivityFixture();
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nivel_educativo' => 'primaria', 'nombre' => 'Caritas', 'tipo' => 'imagenes']);
        $choices = [];
        foreach ([2 => 'En proceso', 4 => 'Logrado'] as $value => $name) {
            $choices[$value] = EscalaOpcion::create(['escala_id' => $scale->id, 'nombre' => $name, 'orden' => $value,
                'valor_equivalente' => (string) $value, 'emoji' => $value === 2 ? '😐' : '🙂', 'aprueba' => $value === 4]);
        }
        foreach ($component->actividades()->orderBy('id')->get() as $index => $activity) {
            app(GradebookService::class)->saveGrades($this->rector, $this->assignment, $period->id, [[
                'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => null,
                'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $choices[$index ? 4 : 2]->id), 'version' => 0]]);
        }
        $grades = Calificacion::orderBy('id')->get()->toArray();
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.valoracion.nombre', 'Logrado')
            ->assertJsonPath('data.resultados.0.secciones.0.valoracion.nombre', 'Logrado')->assertJsonMissingPath('data.resultados.0.raw_value');
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $this->putJson($path.'/actividades', $this->activityBatchPayload($component))->assertOk();
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.valoracion.nombre', 'En proceso');
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.valoracion.nombre', 'Logrado');
        $payload = $this->activityBatchPayload($component);
        $payload['actividades'][0]['peso'] = '90';
        $payload['actividades'][1]['peso'] = '10';
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.valoracion.nombre', 'En proceso');
        $this->assertSame($grades, Calificacion::orderBy('id')->get()->toArray());
    }

    public function test_activity_mode_initializes_only_unweighted_sets_with_an_exact_remainder(): void
    {
        [, , $path] = $this->flexibleFixture();
        foreach (range(1, 7) as $index) {
            $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0, 'nombre' => 'A'.$index, 'fecha' => '2026-02-05'])->assertOk();
        }
        $component = ComponenteEvaluacion::firstOrFail();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $weights = $component->actividades()->orderBy('id')->pluck('peso')->all();
        $this->assertSame([...array_fill(0, 6, '14.2857'), '14.2858'], $weights);
        $this->assertTrue(array_reduce($weights, fn ($sum, $weight) => $sum->plus($weight), BigDecimal::zero())->isEqualTo(100));
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame($weights, $component->actividades()->orderBy('id')->pluck('peso')->all());
    }

    public function test_activity_mode_preserves_custom_weights_when_adding_and_deleting_in_simple_mode(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $selector = ['componente_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id)];
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'crear', 'version' => 0, 'nombre' => 'C', 'fecha' => '2026-02-05'])->assertOk();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame(['20', '80', null], $component->actividades()->orderBy('id')->pluck('peso')->all());
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.estado', 'pendiente');
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $removed = $component->actividades()->where('nombre', 'B')->firstOrFail();
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'eliminar', 'version' => $removed->version,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $removed->id)])->assertOk();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame(['20', null], $component->actividades()->orderBy('id')->pluck('peso')->all());
        $payload = $this->activityBatchPayload($component);
        $payload['actividades'][1]['peso'] = '80';
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $this->assertSame(['20', '80'], $component->actividades()->orderBy('id')->pluck('peso')->all());
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $remaining = $component->actividades()->where('nombre', 'C')->firstOrFail();
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'eliminar', 'version' => $remaining->version,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $remaining->id)])->assertOk();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame(['20'], $component->actividades()->pluck('peso')->all());
    }

    public function test_activity_batch_validates_final_weights_names_dates_and_decimal_precision_without_partial_writes(): void
    {
        [$period, $enrollment, $path, $component] = $this->weightedActivityFixture();
        foreach ($component->actividades()->orderBy('id')->get() as $index => $activity) {
            app(GradebookService::class)->saveGrades($this->rector, $this->assignment, $period->id, [[
                'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => $index ? '3.3' : '3.4', 'version' => 0]]);
        }
        $base = $this->activityBatchPayload($component);
        $snapshot = $this->activityRowsSnapshot();
        $audits = $this->activityAuditCount();
        $invalid = [];
        foreach ([null, 0, '-1', '100.0001', '20.00001', '20.0001', '1e1', 'NaN'] as $weight) {
            $payload = $base;
            $payload['actividades'][0]['peso'] = $weight;
            $invalid[] = $payload;
        }
        $payload = $base;
        unset($payload['actividades'][0]['peso']);
        $invalid[] = $payload;
        foreach (['version', 'nombre', 'fecha', 'actividad_token'] as $field) {
            $payload = $base;
            unset($payload['actividades'][1][$field]);
            $invalid[] = $payload;
        }
        $payload = $base;
        unset($payload['version']);
        $invalid[] = $payload;
        $payload = $base;
        unset($payload['componente_token']);
        $invalid[] = $payload;
        foreach ([' B ', '   '] as $name) {
            $payload = $base;
            $payload['actividades'][0]['nombre'] = $name;
            $invalid[] = $payload;
        }
        foreach (['2025-12-31', '2026-05-01', '2026-02-30'] as $date) {
            $payload = $base;
            $payload['actividades'][1]['fecha'] = $date;
            $invalid[] = $payload;
        }
        Event::fake([ApplicationChanged::class]);
        foreach ($invalid as $payload) {
            $this->putJson($path.'/actividades', $payload)->assertUnprocessable();
            $this->assertSame($snapshot, $this->activityRowsSnapshot());
            $this->assertSame($audits, $this->activityAuditCount());
        }
        Event::assertNotDispatched(ApplicationChanged::class);
        $partial = $base;
        $partial['actividades'][0]['peso'] = '33,3333';
        $partial['actividades'][1]['peso'] = '66.6666';
        $this->putJson($path.'/actividades', $partial)->assertOk();
        $this->assertSame(['33.3333', '66.6666'], $component->actividades()->pluck('peso')->all());
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.estado', 'pendiente')
            ->assertJsonPath('data.resultados.0.provisional', '3.3');
        $exact = $this->activityBatchPayload($component);
        $exact['actividades'][1]['peso'] = '66.6667';
        $this->putJson($path.'/actividades', $exact)->assertOk();
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.estado', 'calculado')
            ->assertJsonPath('data.resultados.0.raw_value', '3.3333333');
    }

    public function test_activity_batch_rejects_stale_versions_duplicates_and_incomplete_membership(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $base = $this->activityBatchPayload($component);
        $snapshot = $this->activityRowsSnapshot();
        $audits = $this->activityAuditCount();
        $stale = $base;
        $stale['actividades'][0]['nombre'] = 'No persistir';
        $stale['actividades'][1]['version'] = 0;
        $this->putJson($path.'/actividades', $stale)->assertConflict();
        $this->putJson($path.'/actividades', [...$base, 'version' => 0])->assertConflict();
        $this->putJson($path.'/actividades', [...$base, 'actividades' => [$base['actividades'][0]]])->assertConflict();
        $this->putJson($path.'/actividades', [...$base, 'actividades' => [$base['actividades'][0], $base['actividades'][0]]])->assertUnprocessable();
        $this->putJson($path.'/actividades', [...$base, 'actividades' => []])->assertUnprocessable();
        $this->assertSame($snapshot, $this->activityRowsSnapshot());
        $this->assertSame($audits, $this->activityAuditCount());
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->putJson($path.'/actividades', $base)->assertConflict();
        $fresh = $this->activityBatchPayload($component);
        $this->putJson($path.'/actividades', [...$fresh, 'actividades' => $base['actividades']])->assertConflict();
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $beforeAddition = $this->activityBatchPayload($component);
        $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0, 'nombre' => 'Remota', 'fecha' => '2026-02-05'])->assertOk();
        $this->putJson($path.'/actividades', $beforeAddition)->assertConflict();
        $withAddition = $this->activityBatchPayload($component);
        $remote = ActividadEvaluacion::where('nombre', 'Remota')->firstOrFail();
        $this->putJson($path.'/actividades', ['operacion' => 'eliminar', 'version' => $remote->version,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $remote->id)])->assertOk();
        $this->putJson($path.'/actividades', $withAddition)->assertNotFound();
    }

    public function test_activity_batch_scopes_opaque_tokens_to_tenant_parent_and_resource_type(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $base = $this->activityBatchPayload($component);
        $otherComponent = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id, 'periodo_id' => $component->periodo_id,
            'nombre' => 'Otra sección', 'modo' => 'SIMPLE_AVERAGE']);
        $otherActivity = ActividadEvaluacion::create(['componente_id' => $otherComponent->id, 'nombre' => 'Ajena', 'fecha' => '2026-02-05']);
        $other = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'batch-other-school', 'name' => 'Otro', 'slug' => 'batch-other-school',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active']));
        $activity = $component->actividades()->first();
        $foreign = $other->run(fn () => OpaqueUrlToken::for('actividad-evaluacion', $activity->id));
        $snapshot = $this->activityRowsSnapshot();
        foreach ([$foreign, OpaqueUrlToken::for('actividad-evaluacion', $otherActivity->id), $base['componente_token'], str_repeat('x', 24)] as $token) {
            $payload = $base;
            $payload['actividades'][1]['actividad_token'] = $token;
            $this->putJson($path.'/actividades', $payload)->assertNotFound();
        }
        $payload = $base;
        $payload['actividades'][0]['actividad_token'] = (string) $activity->id;
        $this->putJson($path.'/actividades', $payload)->assertUnprocessable();
        $payload = $base;
        $payload['actividades'][0]['actividad_id'] = $activity->id;
        $this->putJson($path.'/actividades', $payload)->assertUnprocessable();
        $this->putJson($path.'/actividades', [...$base, 'componente_token' => $base['actividades'][0]['actividad_token']])->assertNotFound();
        $foreignComponent = $other->run(fn () => OpaqueUrlToken::for('componente-evaluacion', $component->id));
        $this->putJson($path.'/actividades', [...$base, 'componente_token' => $foreignComponent])->assertNotFound();
        $subject = $this->subject->replicate();
        $subject->nombre = 'Otra materia';
        $subject->save();
        $assignment = $this->assignment->replicate();
        $assignment->materia_id = $subject->id;
        $assignment->save();
        $outside = $otherComponent->replicate();
        $outside->asignacion_id = $assignment->id;
        $outside->save();
        $this->putJson($path.'/actividades', [...$base,
            'componente_token' => OpaqueUrlToken::for('componente-evaluacion', $outside->id)])->assertNotFound();
        $this->withHeader('X-Legacy-Academic-Ids', '0')->putJson('http://localhost/api/evaluacion/planillas/'.$this->assignment->id.'/'.$component->periodo_id.'/actividades', $base)->assertNotFound();
        $this->assertSame($snapshot, $this->activityRowsSnapshot());
    }

    public function test_activity_batch_requires_effective_edit_permission_and_authorized_assignment(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $this->withToken($this->teacher->createToken('batch-teacher')->plainTextToken);
        app('auth')->forgetGuards();
        $payload = $this->activityBatchPayload($component);
        $this->putJson($path.'/actividades', $payload)->assertForbidden();
        $this->teacher->givePermissionTo(Permission::findOrCreate('notas.actividades.crear', 'web'));
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $payload)->assertForbidden();
        $this->teacher->givePermissionTo(Permission::findOrCreate('notas.actividades.editar', 'web'));
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $payload = $this->activityBatchPayload($component);
        $this->assignment->update(['docente_id' => null]);
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $payload)->assertForbidden();
        $this->teacher->givePermissionTo(Permission::findOrCreate('notas.editar_no_dicta', 'web'));
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $this->defaultHeaders = ['X-Tenant' => $this->school->id];
        app('auth')->forgetGuards();
        $this->putJson($path.'/actividades', $this->activityBatchPayload($component))->assertUnauthorized();
    }

    public function test_activity_batch_pre_report_selector_strict_dates_and_lifecycle_are_enforced(): void
    {
        [$period, , $path, $component] = $this->weightedActivityFixture(true);
        $pre = Preinforme::findOrFail($component->preinforme_id);
        $pre->update(['fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-02-28']);
        $period->refresh()->update(['configuracion_notas' => [...$period->configuracion_notas, 'fechas_estrictas' => true]]);
        $base = $this->activityBatchPayload($component);
        $base['preinforme_token'] = OpaqueUrlToken::for('preinforme', $pre->id);
        $badDate = $base;
        $badDate['actividades'][1]['fecha'] = '2026-03-01';
        $this->putJson($path.'/actividades', $badDate)->assertUnprocessable();
        $otherPre = Preinforme::whereKeyNot($pre->id)->firstOrFail();
        $this->putJson($path.'/actividades', [...$base, 'preinforme_token' => OpaqueUrlToken::for('preinforme', $otherPre->id)])->assertNotFound();
        unset($base['componente_token']);
        $this->putJson($path.'/actividades', $base)->assertOk();
        $base = $this->activityBatchPayload($component);
        $period->update(['estado' => 'cerrado']);
        $this->putJson($path.'/actividades', $base)->assertUnprocessable();
        $period->update(['estado' => 'abierto']);
        $this->year->update(['estado' => 'planificado']);
        $this->putJson($path.'/actividades', $base)->assertUnprocessable();
        $this->year->update(['estado' => 'en_curso']);
        $this->school->update(['plan' => 'esencial']);
        $this->putJson($path.'/actividades', $base)->assertForbidden();
    }

    public function test_activity_batch_rolls_back_rows_audits_and_events_if_a_later_save_fails(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $payload = $this->activityBatchPayload($component);
        $payload['actividades'][0]['nombre'] = 'Primera modificación';
        $payload['actividades'][1]['nombre'] = 'Segunda modificación';
        $snapshot = $this->activityRowsSnapshot();
        $audits = $this->activityAuditCount();
        Event::fake([ApplicationChanged::class]);
        $updates = 0;
        $eventName = 'eloquent.updating: '.ActividadEvaluacion::class;
        Event::listen($eventName, function () use (&$updates): void {
            if (++$updates === 2) {
                throw new \RuntimeException('Simulated second-row failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->putJson($path.'/actividades', $payload);
            $this->fail('The second-row failure must roll back the entire batch.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated second-row failure', $error->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $this->assertSame(2, $updates);
        $this->assertSame($snapshot, $this->activityRowsSnapshot());
        $this->assertSame($audits, $this->activityAuditCount());
        Event::assertNotDispatched(ApplicationChanged::class);
    }

    public function test_activity_batch_simple_mode_validates_explicit_weights_and_rejects_manual_mode(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $payload = $this->activityBatchPayload($component);
        $invalid = $payload;
        $invalid['actividades'][0]['peso'] = 90;
        $this->putJson($path.'/actividades', $invalid)->assertUnprocessable();
        $this->putJson($path.'/actividades', ['operacion' => 'crear', 'version' => 0,
            'componente_token' => $payload['componente_token'], 'nombre' => 'Exceso', 'fecha' => '2026-02-05', 'peso' => 1])->assertUnprocessable();
        $this->assertSame(['20', '80'], $component->actividades()->pluck('peso')->all());
        $this->putJson($path.'/actividades', $payload)->assertOk();
        // An inconsistent legacy/manual section still cannot be edited through the batch endpoint.
        $component->update(['modo' => 'MANUAL']);
        $this->putJson($path.'/actividades', $this->activityBatchPayload($component))->assertUnprocessable();
    }

    public function test_activity_simple_legacy_zero_weights_allow_renaming_but_block_weighted_activation_without_redistribution(): void
    {
        [, , $path, $component] = $this->weightedActivityFixture();
        $this->changeActivityMode($path, $component, 'SIMPLE_AVERAGE');
        $zero = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Legada',
            'fecha' => '2026-02-05', 'peso' => '0', 'version' => 1]);
        $payload = $this->activityBatchPayload($component);
        $this->putJson($path.'/actividades', ['operacion' => 'editar', 'componente_token' => $payload['componente_token'],
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $zero->id), 'version' => 1,
            'nombre' => 'Legada renombrada', 'fecha' => '2026-02-06'])->assertOk();
        $payload = $this->activityBatchPayload($component);
        $payload['actividades'][0]['nombre'] = 'A renombrada';
        $this->putJson($path.'/actividades', $payload)->assertOk();
        $this->assertSame(['20', '80', '0'], $component->actividades()->orderBy('id')->pluck('peso')->all());
        $snapshot = $this->activityRowsSnapshot();
        $audits = $this->activityAuditCount();
        $this->putJson($path.'/actividades', ['operacion' => 'modo', 'version' => $component->fresh()->version,
            'componente_token' => $payload['componente_token'], 'modo' => 'WEIGHTED_AVERAGE'])->assertUnprocessable()
            ->assertJsonPath('message', 'Hay actividades con porcentaje cero. Corrige sus pesos en promedio simple antes de activar o guardar el promedio ponderado.');
        $this->assertSame('SIMPLE_AVERAGE', $component->fresh()->modo);
        $this->assertSame($snapshot, $this->activityRowsSnapshot());
        $this->assertSame($audits, $this->activityAuditCount());
        $fixed = $this->activityBatchPayload($component);
        foreach (['19', '80', '1'] as $index => $weight) {
            $fixed['actividades'][$index]['peso'] = $weight;
        }
        $this->putJson($path.'/actividades', $fixed)->assertOk();
        $this->changeActivityMode($path, $component, 'WEIGHTED_AVERAGE');
        $this->assertSame(['19', '80', '1'], $component->actividades()->orderBy('id')->pluck('peso')->all());
    }

    public function test_legacy_activity_edit_keeps_saved_simple_weights_and_increments_row_version(): void
    {
        [, , $activity] = $this->gradeFixture();
        $activity->update(['peso' => '20']);
        ActividadEvaluacion::create(['componente_id' => $activity->componente_id, 'nombre' => 'B', 'fecha' => '2026-02-05', 'peso' => '80']);
        $version = $activity->fresh()->version;
        $request = Request::create('/', 'PUT', ['componente_id' => $activity->componente_id,
            'nombre' => 'Renombrada', 'fecha' => $activity->fecha, 'peso' => null]);
        $request->setUserResolver(fn () => $this->rector);
        app(EvaluacionController::class)->actividad($request, $activity->id);
        $this->assertSame('20', $activity->fresh()->peso);
        $this->assertSame($version + 1, $activity->fresh()->version);
        $legacyZero = ActividadEvaluacion::create(['componente_id' => $activity->componente_id,
            'nombre' => 'Cero legado', 'fecha' => '2026-02-05', 'peso' => 0]);
        $zeroRequest = Request::create('/', 'PUT', ['componente_id' => $activity->componente_id,
            'nombre' => 'Cero renombrado', 'fecha' => '2026-02-05']);
        $zeroRequest->setUserResolver(fn () => $this->rector);
        app(EvaluacionController::class)->actividad($zeroRequest, $legacyZero->id);
        $this->assertSame('0', $legacyZero->fresh()->peso);
        $legacyZero->delete();
        $component = $activity->componente;
        $beforeMode = $this->activityBatchPayload($component);
        $request = Request::create('/', 'PUT', ['asignacion_id' => $this->assignment->id, 'periodo_id' => $component->periodo_id,
            'nombre' => $component->nombre, 'peso' => $component->peso, 'modo' => 'WEIGHTED_AVERAGE']);
        $request->setUserResolver(fn () => $this->rector);
        app(EvaluacionController::class)->componente($request, $component->id);
        $this->assertSame($beforeMode['version'] + 1, $component->fresh()->version);
        $this->assertSame(['20', '80'], $component->actividades()->pluck('peso')->all());
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->rector->createToken('legacy-batch')->plainTextToken);
        $path = 'http://localhost/api/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $this->assignment->id)
            .'/'.OpaqueUrlToken::for('periodo', $component->periodo_id).'/actividades';
        $this->putJson($path, $beforeMode)->assertConflict();
    }
}
