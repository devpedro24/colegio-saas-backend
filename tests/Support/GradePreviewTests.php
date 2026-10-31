<?php

namespace Tests\Support;

use App\Events\ApplicationChanged;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Preinforme;
use App\Services\FlexibleGradingService;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

trait GradePreviewTests
{
    private function previewFixture(): array
    {
        [$period, $enrollment, $path] = $this->flexibleFixture(true);
        $this->configurePreinformes($period)->assertOk();
        $activities = [];
        foreach (Preinforme::orderBy('orden')->get() as $index => $pre) {
            $component = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id,
                'periodo_id' => $period->id, 'preinforme_id' => $pre->id, 'nombre' => $pre->nombre,
                'peso' => $pre->peso, 'modo' => 'SIMPLE_AVERAGE', 'version' => 0]);
            foreach (['A', 'B'] as $name) {
                $activities[$index][] = ActividadEvaluacion::create([
                    'componente_id' => $component->id, 'nombre' => $name, 'fecha' => '2026-02-05', 'version' => 1]);
            }
        }
        $this->withToken($this->teacher->createToken('preview-teacher')->plainTextToken);
        app('auth')->forgetGuards();

        return [$period, $enrollment, $path, $activities];
    }

    public function test_grade_preview_recalculates_only_the_changed_pre_report_without_saving_or_auditing(): void
    {
        [$period, $enrollment, $path, $activities] = $this->previewFixture();
        $book = app(GradebookService::class);
        foreach ($activities as $index => $pair) {
            foreach ($pair as $activity) {
                $book->saveGrades($this->teacher, $this->assignment, $period->id, [[
                    'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => $index ? '4' : '3', 'version' => 0]]);
            }
        }
        $payload = ['notas' => [['actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activities[0][0]->id),
            'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id), 'valor' => '4', 'version' => 1]]];
        $audits = DB::table('audit_logs')->where('recurso', '!=', 'http')->count();
        Event::fake([ApplicationChanged::class]);
        $result = $this->postJson($path.'/vista-previa?opaque=1', $payload)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.resultados.0.secciones.0.display_value', '3.5')
            ->assertJsonPath('data.resultados.0.secciones.1.display_value', '4')
            ->assertJsonPath('data.resultados.0.display_value', '3.8')->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($result));
        $this->assertSame($audits, DB::table('audit_logs')->where('recurso', '!=', 'http')->count());
        $this->assertSame('3', Calificacion::where('actividad_id', $activities[0][0]->id)->first()->valor);
        $this->assertSame(4, Calificacion::count());
        Event::assertNotDispatched(ApplicationChanged::class);
        $this->getJson($path.'?opaque=1')->assertOk()->assertJsonPath('data.resultados.0.secciones.0.display_value', '3');
        $this->postJson($path.'/vista-previa?opaque=1', ['notas' => [[...$payload['notas'][0], 'version' => 0]]])->assertConflict();
        $this->postJson($path.'/vista-previa?opaque=1', ['notas' => [[...$payload['notas'][0], 'valor' => '6']]])->assertUnprocessable();
        $this->assignment->update(['docente_id' => null]);
        app('auth')->forgetGuards();
        $this->postJson($path.'/vista-previa?opaque=1', $payload)->assertForbidden();
        $this->assignment->update(['docente_id' => $this->teacher->id]);
        $period->update(['estado' => 'cerrado']);
        $this->postJson($path.'/vista-previa?opaque=1', $payload)->assertUnprocessable();
    }

    public function test_grade_preview_uses_visual_equivalents_and_keeps_other_pre_report_categories(): void
    {
        [$period, $enrollment, $path, $activities] = $this->previewFixture();
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nivel_educativo' => 'primaria',
            'nombre' => 'Caritas', 'tipo' => 'imagenes']);
        $choices = [];
        foreach ([2 => 'En proceso', 4 => 'Logrado'] as $value => $name) {
            $choices[$value] = EscalaOpcion::create([
                'escala_id' => $scale->id, 'nombre' => $name, 'orden' => $value, 'valor_equivalente' => (string) $value,
                'emoji' => $value === 2 ? '😐' : '🙂', 'aprueba' => $value === 4]);
        }
        foreach ($activities as $index => $pair) {
            foreach ($pair as $activity) {
                app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
                    'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => null,
                    'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $choices[$index ? 4 : 2]->id), 'version' => 0]]);
            }
        }
        $payload = ['notas' => array_map(fn ($activity) => ['actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
            'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id), 'valor' => null,
            'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $choices[4]->id), 'version' => 1], $activities[0])];
        $this->postJson($path.'/vista-previa?opaque=1', $payload)->assertOk()
            ->assertJsonPath('data.resultados.0.valoracion.nombre', 'Logrado')
            ->assertJsonPath('data.resultados.0.secciones.0.valoracion.nombre', 'Logrado')
            ->assertJsonPath('data.resultados.0.secciones.1.valoracion.nombre', 'Logrado')
            ->assertJsonMissingPath('data.resultados.0.raw_value');
        $this->assertSame('2', Calificacion::where('actividad_id', $activities[0][0]->id)->first()->valor);
    }

    public function test_pre_report_activity_modes_are_shared_and_weights_never_exceed_one_hundred(): void
    {
        [$period, , $path, $activities] = $this->previewFixture();
        $this->withToken($this->rector->createToken('configure-preview')->plainTextToken);
        app('auth')->forgetGuards();
        $component = ComponenteEvaluacion::findOrFail($activities[0][0]->componente_id);
        $other = ComponenteEvaluacion::findOrFail($activities[1][0]->componente_id);
        $other->update(['modo' => 'WEIGHTED_AVERAGE']);
        $activities[1][0]->update(['peso' => '20']);
        $activities[1][1]->update(['peso' => '80']);
        ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'C', 'fecha' => '2026-02-05', 'version' => 1]);
        $selector = ['componente_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
            'preinforme_token' => OpaqueUrlToken::for('preinforme', $component->preinforme_id)];
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'modo', 'modo' => 'WEIGHTED_AVERAGE', 'version' => 0])->assertOk();
        $this->assertSame(['WEIGHTED_AVERAGE'], ComponenteEvaluacion::pluck('modo')->unique()->all());
        $this->assertSame(['33.3333', '33.3333', '33.3334'], $component->actividades()->pluck('peso')->all());
        $this->assertSame(['20', '80'], $other->actividades()->pluck('peso')->all());
        $create = [...$selector, 'operacion' => 'crear', 'nombre' => 'Exceso', 'fecha' => '2026-02-05', 'version' => 0, 'peso' => '1'];
        $this->putJson($path.'/actividades', $create)->assertUnprocessable();
        $this->putJson($path.'/actividades', [...$create, 'peso' => '0'])->assertUnprocessable();
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'editar', 'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activities[0][0]->id),
            'nombre' => 'A', 'fecha' => '2026-02-05', 'version' => 2, 'peso' => '33.3333'])->assertOk();
        $this->putJson($path.'/actividades', [...$selector, 'operacion' => 'modo', 'modo' => 'SIMPLE_AVERAGE', 'version' => 1])->assertOk();
        $this->assertSame(['SIMPLE_AVERAGE'], ComponenteEvaluacion::pluck('modo')->unique()->all());
        $this->assertSame(0, ActividadEvaluacion::whereNotNull('peso')->count());
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'componente_evaluacion', 'accion' => 'UPDATE']);
    }

    public function test_pre_report_mode_alignment_repairs_open_sheets_and_preserves_closed_history(): void
    {
        [$period, , , $activities] = $this->previewFixture();
        $first = ComponenteEvaluacion::findOrFail($activities[0][0]->componente_id);
        $second = ComponenteEvaluacion::findOrFail($activities[1][0]->componente_id);
        $second->update(['modo' => 'WEIGHTED_AVERAGE']);
        foreach ($activities[1] as $activity) {
            $activity->update(['peso' => '50']);
        }
        $migration = require database_path('migrations/tenant/2026_10_27_000001_align_pre_report_activity_modes.php');
        $period->update(['estado' => 'cerrado']);
        $migration->up();
        $this->assertSame('SIMPLE_AVERAGE', $first->fresh()->modo);
        $period->update(['estado' => 'abierto']);
        $migration->up();
        $this->assertSame('WEIGHTED_AVERAGE', $first->fresh()->modo);
        $this->assertSame(['50', '50'], $first->actividades()->pluck('peso')->all());
        $this->assertSame(['50', '50'], $second->actividades()->pluck('peso')->all());
        Preinforme::create(['periodo_id' => $period->id, 'nombre' => 'Tercer avance', 'orden' => 3, 'peso' => '0']);
        $sections = app(FlexibleGradingService::class)->sections($period->fresh(), ComponenteEvaluacion::with('actividades')->get());
        $this->assertSame('WEIGHTED_AVERAGE', $sections[2]['modo']);
    }
}
