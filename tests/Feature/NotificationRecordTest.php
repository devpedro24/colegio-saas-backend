<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SieeConfiguration;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Stancl\Tenancy\DatabaseConfig;
use Tests\TestCase;

final class NotificationRecordTest extends TestCase
{
    private ?Tenant $gradebookSchool = null;

    private string $gradebookActor = 'rector';

    private array $gradebookRecords = [];

    public static function gradebookScales(): array
    {
        return ['numeric' => [false], 'caritas' => [true]];
    }

    #[DataProvider('gradebookScales')]
    public function test_gradebook_grade_saves_record_numeric_and_visual_results_but_previews_do_not(bool $visual): void
    {
        $f = $this->gradebookFixture($visual);
        $this->gradebookAuthenticate($f, 'docente');
        $row = ['actividad_token' => $f['activityToken'], 'matricula_token' => $f['enrollmentToken'],
            'valor' => $visual ? null : '4', 'version' => 0,
            'observacion' => 'Observación confidencial de la evaluación',
            'motivo' => 'Justificación confidencial del registro'];
        if ($visual) {
            $row['escala_opcion_token'] = $f['choiceToken'];
        }
        $input = ['notas' => [$row]];
        $this->postJson($f['sheet'].'/vista-previa', $input)->assertOk()
            ->assertHeaderMissing('X-Notification-Record')->assertJsonPath('data.resultados.0.estado', 'calculado');
        $this->postJson($f['sheet'].'/vista-previa', ['notas' => []])->assertUnprocessable()
            ->assertHeaderMissing('X-Notification-Record');
        $f['school']->run(function () {
            $this->assertSame(0, Calificacion::count());
            $this->assertSame(0, DB::table('user_notification_records')->count());
        });

        $invalid = ['notas' => [[...$row, 'valor' => '6']]];
        $this->assertGradebookRecord($this->putJson($f['sheet'], $invalid)->assertUnprocessable(), 'gradebook.grades.save', 'danger');
        $saved = $this->putJson($f['sheet'], $input)->assertOk()->assertJsonPath('data.calificaciones.0.version', 1);
        $this->assertGradebookRecord($saved, 'gradebook.grades.save', 'updated');
        if ($visual) {
            $saved->assertJsonPath('data.calificaciones.0.valor', null)
                ->assertJsonPath('data.calificaciones.0.escala_opcion_token', $f['choiceToken'])
                ->assertJsonPath('data.resultados.0.valoracion.nombre', 'Alcanzado')
                ->assertJsonMissingPath('data.resultados.0.display_value');
        } else {
            $saved->assertJsonPath('data.calificaciones.0.valor', '4');
        }
        $snapshot = $f['school']->run(fn () => Calificacion::firstOrFail()->toArray());
        $this->assertGradebookRecord($this->putJson($f['sheet'], $input)->assertConflict(), 'gradebook.grades.save', 'danger');
        $this->postJson($f['sheet'].'/vista-previa', $input)->assertConflict()->assertHeaderMissing('X-Notification-Record');
        $this->getJson($f['sheet'])->assertOk()->assertHeaderMissing('X-Notification-Record');
        $f['school']->run(fn () => Periodo::firstOrFail()->update(['estado' => 'cerrado']));
        $this->assertGradebookRecord($this->putJson($f['sheet'], ['notas' => [[...$row, 'version' => 1]]])
            ->assertUnprocessable(), 'gradebook.grades.save', 'danger');
        $this->postJson($f['sheet'].'/vista-previa', ['notas' => [[...$row, 'version' => 1]]])
            ->assertUnprocessable()->assertHeaderMissing('X-Notification-Record');
        $this->gradebookAuthenticate($f, 'estudiante');
        $this->assertGradebookRecord($this->putJson($f['sheet'], $input)->assertForbidden(), 'gradebook.grades.save', 'danger');
        $this->postJson($f['sheet'].'/vista-previa', $input)->assertForbidden()->assertHeaderMissing('X-Notification-Record');
        $f['school']->run(function () use ($snapshot, $visual) {
            $this->assertSame($snapshot, Calificacion::firstOrFail()->toArray());
            $this->assertEquals('4', Calificacion::firstOrFail()->valor);
            $this->assertSame($visual ? EscalaOpcion::where('nombre', 'Alcanzado')->value('id') : null,
                Calificacion::firstOrFail()->escala_opcion_id);
        });
        foreach (['rector', 'docente', 'estudiante'] as $actor) {
            $this->gradebookAuthenticate($f, $actor);
            $this->assertGradebookHistory($f['base']);
        }
    }

    public function test_gradebook_activity_lifecycle_batches_and_modes_record_results_without_losing_conflicted_changes(): void
    {
        $f = $this->gradebookFixture();
        $url = $f['sheet'].'/actividades';
        $section = ['componente_token' => $f['componentToken']];
        $create = [...$section, 'operacion' => 'crear', 'version' => 0, 'nombre' => 'Taller nuevo', 'fecha' => '2028-02-02'];
        $this->assertGradebookRecord($this->putJson($url, [...$create, 'nombre' => ''])->assertUnprocessable(), 'gradebook.activity.store', 'danger');
        $this->assertGradebookRecord($this->putJson($url, $create)->assertOk(), 'gradebook.activity.store', 'success');
        $createdToken = $f['school']->run(fn () => OpaqueUrlToken::for('actividad-evaluacion', ActividadEvaluacion::where('nombre', 'Taller nuevo')->firstOrFail()->id));
        $edit = [...$create, 'operacion' => 'editar', 'actividad_token' => $createdToken, 'version' => 1, 'nombre' => 'Taller revisado'];
        $this->assertGradebookRecord($this->putJson($url, $edit)->assertOk(), 'gradebook.activity.update', 'updated');
        $this->assertGradebookRecord($this->putJson($url, [...$edit, 'nombre' => 'Cambio obsoleto'])->assertConflict(), 'gradebook.activity.update', 'danger');
        $rows = [
            ['actividad_token' => $f['activityToken'], 'version' => 1, 'nombre' => 'Evaluación inicial', 'fecha' => '2028-02-01', 'peso' => '40'],
            ['actividad_token' => $createdToken, 'version' => 2, 'nombre' => 'Taller final', 'fecha' => '2028-02-02', 'peso' => '60'],
        ];
        $batch = [...$section, 'operacion' => 'editar_lote', 'version' => 1, 'actividades' => $rows];
        $before = $f['school']->run(fn () => ActividadEvaluacion::orderBy('id')->get()->toArray());
        $badRows = [[...$rows[0], 'nombre' => 'No debe persistir'], [...$rows[1], 'version' => 0]];
        $this->assertGradebookRecord($this->putJson($url, [...$batch, 'actividades' => $badRows])->assertConflict(), 'gradebook.activity.saveBulk', 'danger');
        $f['school']->run(fn () => $this->assertSame($before, ActividadEvaluacion::orderBy('id')->get()->toArray()));
        $this->assertGradebookRecord($this->putJson($url, $batch)->assertOk(), 'gradebook.activity.saveBulk', 'updated');
        $mode = [...$section, 'operacion' => 'modo', 'version' => 1, 'modo' => 'WEIGHTED_AVERAGE'];
        $this->assertGradebookRecord($this->putJson($url, $mode)->assertOk(), 'gradebook.activity.policy', 'updated');
        $this->assertGradebookRecord($this->putJson($url, [...$mode, 'modo' => 'SIMPLE_AVERAGE'])->assertConflict(), 'gradebook.activity.policy', 'danger');
        $f['school']->run(function () {
            $this->assertSame('WEIGHTED_AVERAGE', ComponenteEvaluacion::firstOrFail()->modo);
            $this->assertSame(2, ComponenteEvaluacion::firstOrFail()->version);
            $this->assertSame(['40', '60'], ActividadEvaluacion::orderBy('id')->get()->pluck('peso')->all());
            $this->assertSame([3, 4], ActividadEvaluacion::orderBy('id')->pluck('version')->all());
        });
        $delete = [...$section, 'operacion' => 'eliminar', 'actividad_token' => $createdToken, 'version' => 4];
        $this->assertGradebookRecord($this->putJson($url, $delete)->assertOk(), 'gradebook.activity.destroy', 'deleted');
        $f['school']->run(function () {
            $this->assertSame(1, ActividadEvaluacion::count());
            $this->assertSame('Evaluación inicial', ActividadEvaluacion::firstOrFail()->nombre);
            Calificacion::create(['actividad_id' => ActividadEvaluacion::firstOrFail()->id,
                'matricula_id' => Matricula::firstOrFail()->id, 'valor' => '4', 'version' => 1,
                'updated_by' => User::where('role', 'docente')->firstOrFail()->id]);
        });
        $protectedDelete = [...$delete, 'actividad_token' => $f['activityToken'], 'version' => 3];
        $this->assertGradebookRecord($this->putJson($url, $protectedDelete)->assertUnprocessable(), 'gradebook.activity.destroy', 'danger');
        $f['school']->run(fn () => Periodo::firstOrFail()->update(['estado' => 'cerrado']));
        foreach ([['store', $create], ['update', [...$edit, 'actividad_token' => $f['activityToken'], 'version' => 3]],
            ['destroy', $protectedDelete], ['saveBulk', $batch], ['policy', [...$mode, 'version' => 2]]] as [$action, $input]) {
            $this->assertGradebookRecord($this->putJson($url, $input)->assertUnprocessable(), 'gradebook.activity.'.$action, 'danger');
        }
        $f['school']->run(fn () => Periodo::firstOrFail()->update(['estado' => 'abierto']));
        $this->gradebookAuthenticate($f, 'estudiante');
        foreach ([['store', $create], ['update', $edit], ['destroy', $protectedDelete], ['saveBulk', $batch], ['policy', $mode]] as [$action, $input]) {
            $this->assertGradebookRecord($this->putJson($url, $input)->assertForbidden(), 'gradebook.activity.'.$action, 'danger');
        }
        $f['school']->run(function () {
            $this->assertSame(1, ActividadEvaluacion::count());
            $this->assertSame(3, ActividadEvaluacion::firstOrFail()->version);
            $this->assertSame(1, Calificacion::count());
        });
        foreach (['rector', 'estudiante'] as $actor) {
            $this->gradebookAuthenticate($f, $actor);
            $this->assertGradebookHistory($f['base']);
        }
    }

    public function test_gradebook_component_and_activity_endpoints_record_creates_updates_and_failures(): void
    {
        $f = $this->gradebookFixture();
        $components = $f['base'].'/evaluacion/componentes';
        $input = ['asignacion_token' => $f['assignmentToken'], 'periodo_token' => $f['periodToken'],
            'nombre' => 'Proyecto', 'modo' => 'SIMPLE_AVERAGE'];
        $created = $this->postJson($components, $input)->assertCreated();
        $this->assertGradebookRecord($created, 'gradebook.component.store', 'success');
        $component = $created->json('data.url_token');
        $this->assertGradebookRecord($this->putJson($components.'/'.$component, [...$input, 'nombre' => 'Proyecto final'])->assertOk(), 'gradebook.component.update', 'updated');
        $this->assertGradebookRecord($this->postJson($components, [...$input, 'nombre' => 'Proyecto final'])->assertUnprocessable(), 'gradebook.component.store', 'danger');
        $activities = $f['base'].'/evaluacion/actividades';
        $activityInput = ['componente_token' => $component, 'nombre' => 'Entrega', 'fecha' => '2028-02-10'];
        $created = $this->postJson($activities, $activityInput)->assertCreated();
        $this->assertGradebookRecord($created, 'gradebook.activity.store', 'success');
        $activity = $created->json('data.url_token');
        $this->assertGradebookRecord($this->putJson($activities.'/'.$activity, [...$activityInput, 'nombre' => 'Entrega revisada'])->assertOk(), 'gradebook.activity.update', 'updated');
        $this->assertGradebookRecord($this->postJson($activities, [...$activityInput, 'fecha' => '2029-01-01'])->assertUnprocessable(), 'gradebook.activity.store', 'danger');
        $f['school']->run(fn () => Periodo::firstOrFail()->update(['estado' => 'cerrado']));
        $this->assertGradebookRecord($this->putJson($components.'/'.$component, $input)->assertUnprocessable(), 'gradebook.component.update', 'danger');
        $this->assertGradebookRecord($this->putJson($activities.'/'.$activity, $activityInput)->assertUnprocessable(), 'gradebook.activity.update', 'danger');
        $this->gradebookAuthenticate($f, 'estudiante');
        $this->assertGradebookRecord($this->postJson($components, $input)->assertForbidden(), 'gradebook.component.store', 'danger');
        $this->assertGradebookRecord($this->putJson($activities.'/'.$activity, $activityInput)->assertForbidden(), 'gradebook.activity.update', 'danger');
        $f['school']->run(function () {
            $this->assertSame(2, ComponenteEvaluacion::count());
            $this->assertSame(2, ActividadEvaluacion::count());
            $this->assertSame(2, ComponenteEvaluacion::where('nombre', 'Proyecto final')->firstOrFail()->version);
            $this->assertSame(2, ActividadEvaluacion::where('nombre', 'Entrega revisada')->firstOrFail()->version);
        });
        foreach (['rector', 'estudiante'] as $actor) {
            $this->gradebookAuthenticate($f, $actor);
            $this->assertGradebookHistory($f['base']);
        }
    }

    #[DataProvider('gradebookScales')]
    public function test_gradebook_manual_component_subject_area_and_annual_results_record_saves_and_annulments(bool $visual): void
    {
        $f = $this->gradebookFixture($visual, ['usar_areas' => true, 'modo_area' => 'MANUAL', 'modo_anual' => 'MANUAL']);
        $f['school']->run(fn () => ActividadEvaluacion::query()->delete());
        $this->assertGradebookRecord($this->putJson($f['sheet'].'/actividades', [
            'operacion' => 'modo', 'componente_token' => $f['componentToken'], 'version' => 1, 'modo' => 'MANUAL',
        ])->assertOk(), 'gradebook.activity.policy', 'updated');

        // Every manual endpoint must distinguish a visual selection (valor=null) from an annulment.
        $exercise = function (string $url, string $module, string $table, array $extra = [], bool $restore = false) use ($f, $visual): void {
            $input = [...$extra, 'valor' => $visual ? null : '4', 'version' => 0,
                'motivo' => 'Justificación confidencial del registro'];
            if ($visual) {
                $input['escala_opcion_token'] = $f['choiceToken'];
            }
            $query = fn () => DB::table($table)->when(isset($extra['ambito']), fn ($q) => $q->whereNotNull($extra['ambito'].'_id'));
            $this->assertGradebookRecord($this->putJson($url, [...$input, 'valor' => '6'])->assertUnprocessable(), $module.'.save', 'danger');
            $this->assertGradebookRecord($this->putJson($url, $input)->assertOk(), $module.'.save', 'updated');
            $snapshot = $f['school']->run(function () use ($query) {
                $row = $query()->first();
                $this->assertSame('vigente', $row->estado);
                $this->assertSame(1, $row->version);
                $this->assertTrue(BigDecimal::of($row->valor)->isEqualTo('4'));

                return (array) $row;
            });
            $this->assertGradebookRecord($this->putJson($url, $input)->assertConflict(), $module.'.save', 'danger');
            $f['school']->run(fn () => $this->assertSame($snapshot, (array) $query()->first()));
            $annul = [...$extra, 'valor' => null, 'version' => 1, 'motivo' => 'Justificación confidencial de la anulación'];
            $this->assertGradebookRecord($this->putJson($url, $annul)->assertOk(), $module.'.destroy', 'deleted');
            $this->assertGradebookRecord($this->putJson($url, [...$annul, 'version' => 2])->assertUnprocessable(), $module.'.destroy', 'danger');
            $f['school']->run(function () use ($query) {
                $row = $query()->first();
                $this->assertSame('anulado', $row->estado);
                $this->assertSame(2, $row->version);
                $this->assertNull($row->valor);
            });
            $annual = $table === 'resultados_manuales_anuales';
            $f['school']->run(fn () => $annual
                ? AnoLectivo::firstOrFail()->update(['estado' => 'cerrado'])
                : Periodo::firstOrFail()->update(['estado' => 'cerrado']));
            $this->assertGradebookRecord($this->putJson($url, [...$input, 'version' => 2])->assertUnprocessable(), $module.'.save', 'danger');
            $this->gradebookAuthenticate($f, in_array($module, ['gradebook.manualArea', 'gradebook.manualAnnual'], true) ? 'docente' : 'estudiante');
            $this->assertGradebookRecord($this->putJson($url, [...$input, 'version' => 2])->assertForbidden(), $module.'.save', 'danger');
            $this->gradebookAuthenticate($f, 'rector');
            $f['school']->run(fn () => $annual
                ? AnoLectivo::firstOrFail()->update(['estado' => 'en_curso'])
                : Periodo::firstOrFail()->update(['estado' => 'abierto']));
            $f['school']->run(fn () => $this->assertSame(2, $query()->value('version')));
            if ($restore) {
                $this->assertGradebookRecord($this->putJson($url, [...$input, 'version' => 2])->assertOk(), $module.'.save', 'updated');
                $f['school']->run(fn () => $this->assertSame(3, $query()->value('version')));
            }
        };
        $exercise($f['sheet'].'/componentes/'.$f['componentToken'].'/resultados-manuales/'.$f['enrollmentToken'],
            'gradebook.manualComponent', 'resultados_manuales_componente');
        $f['school']->run(function () {
            $year = AnoLectivo::firstOrFail();
            $year->update(['siee' => [...$year->siee, 'modo_asignatura' => 'MANUAL']]);
        });
        $exercise($f['sheet'].'/resultados-manuales/'.$f['enrollmentToken'],
            'gradebook.manualSubject', 'resultados_manuales_materia', restore: true);
        $exercise($f['base'].'/evaluacion/areas/manual/'.$f['groupToken'].'/'.$f['periodToken'].'/'.$f['areaToken'].'/'.$f['enrollmentToken'],
            'gradebook.manualArea', 'resultados_manuales_area', restore: true);
        $annual = $f['base'].'/evaluacion/anuales/manual/'.$f['groupToken'].'/'.$f['enrollmentToken'];
        $annualInput = ['ambito' => 'materia', 'materia_token' => $f['subjectToken'], 'valor' => '4',
            'motivo' => 'Justificación confidencial del registro', 'version' => 0];
        $this->assertGradebookRecord($this->putJson($annual, $annualInput)->assertUnprocessable(), 'gradebook.manualAnnual.save', 'danger');
        $f['school']->run(fn () => Periodo::firstOrFail()->update(['estado' => 'cerrado']));
        foreach (['materia' => $f['subjectToken'], 'area' => $f['areaToken']] as $scope => $token) {
            $exercise($annual, 'gradebook.manualAnnual', 'resultados_manuales_anuales', ['ambito' => $scope, $scope.'_token' => $token]);
        }
        foreach (['rector', 'docente', 'estudiante'] as $actor) {
            $this->gradebookAuthenticate($f, $actor);
            $this->assertGradebookHistory($f['base']);
        }
    }

    private function gradebookFixture(bool $visual = false, array $siee = []): array
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Planilla de prueba', 'slug' => 'planilla-notificaciones-'.uniqid(),
            'plan' => Tenant::PLAN_PREMIUM, 'rector_email' => 'rector@gradebook.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        $this->gradebookSchool = $school;
        $tokens = $school->run(function () use ($visual, $siee) {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A', 'estado' => 'en_curso',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 1, 'periodo_sumatorio' => true]);
            $scale = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Numérica',
                'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
            $method = MetodoAprobacion::create(['ano_lectivo_id' => $year->id, 'calculo_nota' => 'promedio_simple',
                'nota_minima' => '3', 'ambito' => 'materia']);
            $year->update(['siee' => [...SieeConfiguration::DEFAULTS, ...$siee, 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
            $period = Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Único', 'orden' => 1,
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'peso' => 100, 'estado' => 'abierto']);
            $campus = Sede::create(['nombre' => 'Principal', 'estado' => 'activa']);
            $shift = Jornada::create(['ano_lectivo_id' => $year->id, 'sede_id' => $campus->id,
                'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'estado' => 'activo']);
            $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'jornada_id' => $shift->id,
                'sede_id' => $campus->id, 'nombre' => '01A', 'estado' => 'activo']);
            $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
            $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id,
                'nombre' => 'Biología', 'intensidad_horaria' => 2, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id,
                'materia_id' => $subject->id, 'area_id' => $area->id, 'peso_area' => '100']);
            $bearers = ['rector' => $rector->createToken('gradebook-test')->plainTextToken];
            foreach (['docente' => 'Docente de prueba', 'estudiante' => 'Estudiante confidencial'] as $role => $name) {
                $user = User::create(['name' => $name, 'email' => $role.'@gradebook.test', 'password' => bcrypt('Temporary1!abc'),
                    'role' => $role, 'status' => 'active', 'must_change_password' => false]);
                $user->assignRole($role);
                $bearers[$role] = $user->createToken('gradebook-test')->plainTextToken;
            }
            $assignment = AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                'materia_id' => $subject->id, 'docente_id' => User::where('role', 'docente')->firstOrFail()->id]);
            $enrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                'estudiante_id' => User::where('role', 'estudiante')->firstOrFail()->id, 'estado' => 'activa']);
            $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                'nombre' => 'Actividades', 'peso' => 100, 'modo' => 'SIMPLE_AVERAGE', 'es_directo' => false, 'version' => 1]);
            $activity = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Taller', 'fecha' => '2028-02-01', 'version' => 1]);
            $choiceToken = null;
            if ($visual) {
                $faces = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Caritas',
                    'nivel_educativo' => $level->nivel_educativo, 'tipo' => 'imagenes']);
                $choice = EscalaOpcion::create(['escala_id' => $faces->id, 'nombre' => 'Alcanzado', 'orden' => 1,
                    'valor_equivalente' => '4', 'emoji' => '🙂', 'aprueba' => true]);
                EscalaOpcion::create(['escala_id' => $faces->id, 'nombre' => 'En proceso', 'orden' => 2,
                    'valor_equivalente' => '2', 'emoji' => '😐', 'aprueba' => false]);
                $choiceToken = OpaqueUrlToken::for('escala-opcion', $choice->id);
            }

            return ['bearers' => $bearers, 'choiceToken' => $choiceToken,
                'assignmentToken' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                'periodToken' => OpaqueUrlToken::for('periodo', $period->id),
                'componentToken' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
                'activityToken' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
                'enrollmentToken' => OpaqueUrlToken::for('matricula', $enrollment->id),
                'groupToken' => OpaqueUrlToken::for('grupo', $group->id), 'areaToken' => OpaqueUrlToken::for('area', $area->id),
                'subjectToken' => OpaqueUrlToken::for('materia', $subject->id)];
        });
        $base = 'http://'.$school->slug.'.localhost/api';
        $fixture = [...$tokens, 'school' => $school, 'base' => $base,
            'sheet' => $base.'/evaluacion/planillas/'.$tokens['assignmentToken'].'/'.$tokens['periodToken']];
        $this->gradebookAuthenticate($fixture, 'rector');

        return $fixture;
    }

    private function gradebookAuthenticate(array $fixture, string $role): void
    {
        $this->gradebookActor = $role;
        $this->withToken($fixture['bearers'][$role]);
        app('auth')->forgetGuards();
    }

    private function assertGradebookRecord(TestResponse $response, string $event, string $variant): void
    {
        $response->assertHeader('X-Notification-Record');
        $token = $response->headers->get('X-Notification-Record');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/D', $token);
        $this->assertArrayNotHasKey($token, $this->gradebookRecords);
        $this->gradebookSchool->run(function () use ($token, $event, $variant, $response) {
            $rows = DB::table('user_notification_records')->where('url_token', $token)->get();
            $this->assertCount(1, $rows);
            $record = $rows->first();
            $this->assertSame($event, $record->event);
            $this->assertSame($variant, $record->variant);
            $this->assertSame(User::where('role', $this->gradebookActor)->firstOrFail()->id, $record->user_id);
            if (in_array($response->status(), [409, 422], true)) {
                $this->assertNotEmpty($record->message);
            } else {
                $this->assertNull($record->message);
            }
        });
        $this->gradebookRecords[$token] = ['token' => $token, 'event' => $event, 'variant' => $variant, 'actor' => $this->gradebookActor];
    }

    private function assertGradebookHistory(string $base): void
    {
        $expected = array_filter($this->gradebookRecords, fn ($row) => $row['actor'] === $this->gradebookActor);
        // A user_id query cannot disclose another account's records.
        $first = $this->getJson($base.'/notification-records?user_id=1')->assertOk()
            ->assertHeaderMissing('X-Notification-Record')->assertJsonPath('meta.total', count($expected));
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $rows = $first->json('data');
        for ($page = 2; $page <= $first->json('meta.last_page'); $page++) {
            $rows = [...$rows, ...$this->getJson($base.'/notification-records?page='.$page)->assertOk()->json('data')];
        }
        $this->assertEqualsCanonicalizing(array_keys($expected), array_column($rows, 'token'));
        foreach ($rows as $row) {
            $this->assertSame($expected[$row['token']]['event'], $row['event']);
            $this->assertSame($expected[$row['token']]['variant'], $row['variant']);
        }
        $serialized = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $serialized);
        foreach (['Observación confidencial', 'Justificación confidencial', 'Estudiante confidencial', '@gradebook.test', 'Temporary1!abc'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->getJson($base.'/notification-records?user_id=1')->assertOk()->assertExactJson($first->json());
        $this->gradebookSchool->run(fn () => $this->assertSame(count($this->gradebookRecords), DB::table('user_notification_records')->count()));
    }

    public function test_preinformes_and_indicators_record_results_and_preserve_versions_permissions_and_locks(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Indicadores de prueba', 'slug' => 'indicadores-notificaciones-'.uniqid(),
            'plan' => Tenant::PLAN_PREMIUM, 'rector_email' => 'rector@indicators.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $teacherBearer, $yearToken, $periodToken, $gradeToken, $groupToken, $subjectToken] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A', 'estado' => 'en_curso',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 4]);
            $period = Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primer período', 'orden' => 1,
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-03-31', 'peso' => 25, 'estado' => 'abierto']);
            $campus = Sede::create(['nombre' => 'Principal', 'estado' => 'activa']);
            $shift = Jornada::create(['ano_lectivo_id' => $year->id, 'sede_id' => $campus->id,
                'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria',
                'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'estado' => 'activo']);
            $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'jornada_id' => $shift->id,
                'sede_id' => $campus->id, 'nombre' => '01A', 'estado' => 'activo']);
            $subject = Materia::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Matemáticas', 'intensidad_horaria' => 2, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'materia_id' => $subject->id]);
            $teacher = User::create(['name' => 'Docente de prueba', 'email' => 'docente@indicators.test',
                'password' => bcrypt('Temporary1!abc'), 'role' => 'docente', 'status' => 'active', 'must_change_password' => false]);
            $teacher->assignRole('docente');
            $teacher->givePermissionTo(['indicadores.grupo.ver', 'indicadores.grupo.editar']);
            AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                'materia_id' => $subject->id, 'docente_id' => $teacher->id]);

            return [$rector->createToken('test')->plainTextToken, $teacher->createToken('test')->plainTextToken,
                OpaqueUrlToken::for('ano-lectivo', $year->id), OpaqueUrlToken::for('periodo', $period->id),
                OpaqueUrlToken::for('grado', $grade->id), OpaqueUrlToken::for('grupo', $group->id), OpaqueUrlToken::for('materia', $subject->id)];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api';
        $preUrl = $base.'/preinformes/'.$periodToken;
        $input = ['version' => 0, 'usar_preinformes' => true, 'fechas_estrictas' => false, 'modo' => 'WEIGHTED_AVERAGE',
            'preinformes' => [['nombre' => 'Primero', 'peso' => '40'], ['nombre' => 'Segundo', 'peso' => '60']]];
        $this->putJson($preUrl, [...$input, 'preinformes' => [['nombre' => '', 'peso' => '100']]])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($preUrl, $input)->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($preUrl, $input)->assertConflict()->assertHeader('X-Notification-Record');
        $pre = $this->getJson($base.'/preinformes?ano_lectivo_token='.$yearToken)->assertOk()->json('data.periodos.0.preinformes');
        $input['version'] = 1;
        $input['preinformes'] = [[...$pre[0], 'nombre' => 'Renombrado', 'peso' => '20'], [...$pre[1], 'peso' => '80']];
        $this->putJson($preUrl, $input)->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($preUrl, [...$input, 'version' => 2, 'preinformes' => [[...$pre[0], 'peso' => '100']]])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($preUrl, [...$input, 'version' => 3, 'usar_preinformes' => false, 'preinformes' => []])
            ->assertOk()->assertHeader('X-Notification-Record');
        $school->run(function () {
            $this->assertSame(0, Preinforme::count());
            Periodo::firstOrFail()->update(['estado' => 'cerrado']);
        });
        $this->putJson($preUrl, [...$input, 'version' => 4])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $school->run(fn () => Periodo::firstOrFail()->update(['estado' => 'abierto']));
        $policyUrl = $base.'/indicadores/politica/'.$yearToken;
        $policy = ['version' => 0, 'alcance' => 'grupo', 'docente_puede_editar' => true, 'confirmar_copia' => false];
        $this->putJson($policyUrl, [...$policy, 'alcance' => 'invalido'])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($policyUrl, $policy)->assertOk()->assertHeader('X-Notification-Record');
        $sheetUrl = $base.'/indicadores/tabla/'.$subjectToken;
        $sheet = ['grupo_token' => $groupToken, 'periodo_token' => $periodToken, 'version' => 0, 'texto_html' => '<p><strong>Reconoce patrones.</strong></p>'];
        $this->putJson($sheetUrl, [...$sheet, 'texto_html' => ''])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($sheetUrl, $sheet)->assertOk()->assertHeader('X-Notification-Record')->assertJsonPath('data.version', 1);
        $this->putJson($sheetUrl, $sheet)->assertConflict()->assertHeader('X-Notification-Record');
        // Assigned teachers use the same endpoint, but records stay private to their account.
        $this->withToken($teacherBearer);
        app('auth')->forgetGuards();
        $this->putJson($sheetUrl, [...$sheet, 'version' => 1, 'texto_html' => '<ul><li>Compara cantidades.</li></ul>'])
            ->assertOk()->assertHeader('X-Notification-Record')->assertJsonPath('data.version', 2);
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson($preUrl, [...$input, 'version' => 4])->assertForbidden()->assertHeader('X-Notification-Record');
        $this->withToken($bearer);
        app('auth')->forgetGuards();
        $query = '?'.http_build_query(['grupo_token' => $groupToken, 'periodo_token' => $periodToken, 'version' => 2]);
        $school->run(fn () => Periodo::firstOrFail()->update(['estado' => 'cerrado']));
        $this->deleteJson($sheetUrl.$query)->assertUnprocessable()->assertHeader('X-Notification-Record');
        $school->run(fn () => Periodo::firstOrFail()->update(['estado' => 'abierto']));
        $this->deleteJson($sheetUrl.$query)->assertOk()->assertHeader('X-Notification-Record')->assertJsonPath('data.texto_html', '');
        $this->putJson($policyUrl, [...$policy, 'version' => 1, 'alcance' => 'grado', 'confirmar_copia' => true])
            ->assertOk()->assertHeader('X-Notification-Record');
        $gradeInput = ['ano_token' => $yearToken, 'grado_token' => $gradeToken, 'materia_token' => $subjectToken,
            'periodos_orden' => [1], 'texto' => 'Resuelve situaciones cotidianas.', 'orden' => 1];
        $this->postJson($base.'/indicadores', [...$gradeInput, 'texto' => ''])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $indicator = $this->postJson($base.'/indicadores', $gradeInput)->assertCreated()->assertHeader('X-Notification-Record')->json('data');
        $this->putJson($base.'/indicadores/vinculos/'.$indicator['vinculo_token'].'/texto', ['version' => 1, 'texto' => 'Organiza colecciones.'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($base.'/indicadores/'.$indicator['indicador_token'], ['version' => 2, 'texto' => 'Clasifica colecciones.', 'orden' => 1])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($base.'/indicadores/vinculos/'.$indicator['vinculo_token'], ['version' => 2, 'periodos_orden' => [1], 'usar_revision_actual' => true])
            ->assertOk()->assertHeader('X-Notification-Record');
        $anotherSubject = $school->run(function () {
            $first = Materia::firstOrFail();
            $next = Materia::create(['ano_lectivo_id' => $first->ano_lectivo_id, 'nombre' => 'Geometría', 'intensidad_horaria' => 1, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $first->ano_lectivo_id,
                'grado_id' => Grado::firstOrFail()->id, 'materia_id' => $next->id]);

            return OpaqueUrlToken::for('materia', $next->id);
        });
        $this->postJson($base.'/indicadores/'.$indicator['indicador_token'].'/vinculos', [
            'ano_token' => $yearToken, 'grado_token' => $gradeToken, 'materia_token' => $anotherSubject, 'periodos_orden' => [1],
        ])->assertCreated()->assertHeader('X-Notification-Record');
        $rows = $this->getJson($base.'/notification-records')->assertOk();
        $records = [...$rows->json('data'), ...$this->getJson($base.'/notification-records?page=2')->assertOk()->json('data')];
        foreach (['preinformes.save', 'indicators.policy.update', 'indicators.sheet.save', 'indicators.sheet.destroy',
            'indicators.base.store', 'indicators.base.update', 'indicators.binding.update', 'indicators.binding.store'] as $event) {
            $this->assertContains($event, array_column($records, 'event'));
        }
        $this->assertTrue(collect($records)->contains(fn ($row) => $row['variant'] === 'danger' && str_contains($row['message'] ?? '', 'otra sesión')));
        $this->assertStringNotContainsString('Reconoce patrones', json_encode($records));
        $this->getJson($base.'/notification-records')->assertOk()->assertExactJson($rows->json());
    }

    public function test_siee_configuration_and_curriculum_results_are_persisted_without_bypassing_locks(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'SIEE de prueba', 'slug' => 'siee-notificaciones-'.uniqid(), 'rector_email' => 'rector@siee.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $yearToken, $scaleToken, $methodToken, $gradeToken, $subjectTokens] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 4, 'estado' => 'planificado']);
            $scale = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Numérica',
                'tipo' => 'numerica', 'valor_min' => 1, 'valor_max' => 5, 'decimales' => 1]);
            $method = MetodoAprobacion::create(['ano_lectivo_id' => $year->id,
                'calculo_nota' => 'promedio_simple', 'nota_minima' => 3, 'ambito' => 'materia']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria',
                'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id,
                'nombre' => 'Primero', 'estado' => 'activo']);
            $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
            $subjects = [];
            foreach (['Biología', 'Física'] as $name) {
                $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id,
                    'nombre' => $name, 'intensidad_horaria' => 2, 'estado' => 'activo']);
                $subjects[] = OpaqueUrlToken::for('materia', $subject->id);
            }

            return [$rector->createToken('test')->plainTextToken, OpaqueUrlToken::for('ano-lectivo', $year->id),
                OpaqueUrlToken::for('escala-valorativa', $scale->id), OpaqueUrlToken::for('metodo-aprobacion', $method->id),
                OpaqueUrlToken::for('grado', $grade->id), $subjects];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api';
        $url = $base.'/siee/'.$yearToken;
        $configuration = ['usar_areas' => true, 'modo_area' => 'WEIGHTED_AVERAGE',
            'modo_asignatura' => 'SIMPLE_AVERAGE', 'modo_anual' => 'SIMPLE_AVERAGE',
            'redondeo' => 'HALF_DOWN', 'precision_calculo' => 8, 'recuperacion' => 'REPLACE',
            'mostrar_final' => true, 'etiqueta_final' => 'Definitiva anual', 'escala_token' => $scaleToken, 'metodo_token' => $methodToken];
        $this->putJson($url, [...$configuration, 'etiqueta_final' => ''])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($url, $configuration)->assertOk()->assertHeader('X-Notification-Record')
            ->assertJsonPath('data.configuracion.etiqueta_final', 'Definitiva anual');
        $row = ['grado_token' => $gradeToken, 'materia_token' => $subjectTokens[0], 'peso_area' => '100'];
        $this->putJson($url.'/curriculo', [...$row, 'peso_area' => '101'])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($url.'/curriculo', $row)->assertOk()->assertHeader('X-Notification-Record');
        $items = [[...$row, 'peso_area' => '40'], [...$row, 'materia_token' => $subjectTokens[1], 'peso_area' => '60']];
        $this->putJson($url.'/curriculo/masivo', ['items' => $items])->assertOk()
            ->assertHeader('X-Notification-Record')->assertJsonPath('data.guardados', 2);
        // Even when the first row could be saved, a later invalid row rolls back the entire operation.
        $this->putJson($url.'/curriculo/masivo', ['items' => [[...$row, 'peso_area' => '50'], $row]])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($url.'/curriculo/masivo', ['items' => [[...$row, 'peso_area' => '50'],
            [...$row, 'materia_token' => str_repeat('z', 24)]]])->assertNotFound()->assertHeader('X-Notification-Record');
        $school->run(function () {
            Periodo::create(['ano_lectivo_id' => AnoLectivo::firstOrFail()->id, 'nombre' => 'Primer período', 'orden' => 1,
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-03-31', 'peso' => 25, 'estado' => 'cerrado']);
        });
        $this->putJson($url, [...$configuration, 'etiqueta_final' => 'No guardar'])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $school->run(fn () => AnoLectivo::firstOrFail()->update(['estado' => 'cerrado']));
        $this->putJson($url.'/curriculo', [...$row, 'peso_area' => '50'])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($url.'/curriculo/masivo', ['items' => $items])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->getJson($url)->assertOk()->assertHeaderMissing('X-Notification-Record')
            ->assertJsonPath('data.editable', false)->assertJsonPath('data.curriculo_editable', false)
            ->assertJsonPath('data.configuracion.etiqueta_final', 'Definitiva anual');
        $school->run(function () {
            $this->assertSame(['40', '60'], DB::table('materias_curriculares')->orderBy('materia_id')->pluck('peso_area')->all());
        });
        $first = $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 10);
        $this->assertCount(7, array_filter($first->json('data'), fn ($row) => $row['variant'] === 'danger'));
        $this->assertCount(3, array_filter($first->json('data'), fn ($row) => $row['variant'] === 'updated'));
        foreach (['siee.configuration.update', 'siee.curriculum.save', 'siee.curriculum.saveBulk'] as $event) {
            $this->assertContains($event, array_column($first->json('data'), 'event'));
        }
        $this->getJson($base.'/notification-records')->assertOk()->assertExactJson($first->json());
    }

    public function test_academic_parameters_categories_and_images_are_recorded(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Parámetros de prueba', 'slug' => 'parametros-notificaciones-'.uniqid(),
            'rector_email' => 'rector@parameters.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $yearToken] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 4, 'estado' => 'planificado']);

            return [$rector->createToken('test')->plainTextToken, OpaqueUrlToken::for('ano-lectivo', $year->id)];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api';
        $resources = [
            'escalas' => ['module' => 'scale', 'input' => ['nombre' => 'Escala institucional', 'tipo' => 'numerica',
                'nivel_educativo' => null, 'valor_min' => 1, 'valor_max' => 5, 'decimales' => 1,
                'desempenos' => [['nombre' => 'Bajo', 'desde' => '1.0', 'hasta' => '2.9'],
                    ['nombre' => 'Básico', 'desde' => '3.0', 'hasta' => '5.0']]],
                'invalid' => ['nombre' => '']],
            'metodos-aprobacion' => ['module' => 'approval', 'input' => ['calculo_nota' => 'promedio_simple',
                'nota_minima' => 3, 'ambito' => 'materia'], 'invalid' => ['nota_minima' => -1]],
            'modelos-pedagogicos' => ['module' => 'model', 'input' => ['nivel_educativo' => 'primaria',
                'docente_unico' => true, 'salon_fijo' => true, 'tiene_director_grupo' => true],
                'invalid' => ['nivel_educativo' => 'invalido']],
        ];
        $tokens = [];
        foreach ($resources as $path => $resource) {
            $input = ['ano_lectivo_token' => $yearToken, ...$resource['input']];
            $tokens[$path] = $this->postJson($base.'/config/'.$path.'?opaque=1', $input)
                ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
            $this->putJson($base.'/config/'.$path.'/'.$tokens[$path].'?opaque=1', $input)
                ->assertOk()->assertHeader('X-Notification-Record');
            $this->postJson($base.'/config/'.$path.'?opaque=1', [...$input, ...$resource['invalid']])
                ->assertUnprocessable()->assertHeader('X-Notification-Record');
            // These endpoints upsert: an existing row is an update, never another creation.
            $this->postJson($base.'/config/'.$path.'?opaque=1', $input)
                ->assertOk()->assertHeader('X-Notification-Record');
        }
        $school->run(function () {
            AnoLectivo::firstOrFail()->update(['siee' => [
                'escala_id' => EscalaValorativa::where('tipo', 'numerica')->firstOrFail()->id,
                'metodo_id' => MetodoAprobacion::firstOrFail()->id,
            ]]);
        });
        $imageScale = $this->postJson($base.'/config/escalas?opaque=1', ['ano_lectivo_token' => $yearToken,
            'nombre' => 'Caritas', 'tipo' => 'imagenes', 'nivel_educativo' => 'preescolar'])
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $optionsUrl = $base.'/config/escalas/'.$imageScale.'/opciones?opaque=1';
        $choices = [
            ['nombre' => 'Bien', 'valor_equivalente' => '5', 'emoji' => '🙂', 'aprueba' => true],
            ['nombre' => 'En proceso', 'valor_equivalente' => '2.5', 'emoji' => '😐', 'aprueba' => false],
        ];
        $saved = $this->putJson($optionsUrl, ['opciones' => $choices])
            ->assertOk()->assertHeader('X-Notification-Record')->json('data');
        $this->putJson($optionsUrl, ['opciones' => []])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $revised = [[...$choices[0], 'nombre' => 'Excelente', 'url_token' => $saved[0]['url_token']],
            ['nombre' => 'Necesita apoyo', 'valor_equivalente' => '1.5', 'emoji' => '😟', 'aprueba' => false]];
        $this->putJson($optionsUrl, ['opciones' => $revised])->assertOk()->assertHeader('X-Notification-Record');
        $imageUrl = $base.'/config/escalas/'.$imageScale.'/opciones/'.$saved[0]['url_token'].'/imagen?opaque=1';
        $this->withHeaders(['Accept' => 'application/json']);
        Storage::fake('tenant');
        $this->post($imageUrl, ['imagen' => UploadedFile::fake()->create('invalido.pdf', 1, 'application/pdf')])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->post($imageUrl, ['imagen' => UploadedFile::fake()->image('carita.png', 64, 64)])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->get($imageUrl)->assertOk()->assertHeaderMissing('X-Notification-Record')->assertHeader('Content-Type', 'image/png');
        $school->run(fn () => AnoLectivo::firstOrFail()->update(['estado' => 'cerrado']));
        $this->putJson($optionsUrl, ['opciones' => $revised])->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->post($imageUrl, ['imagen' => UploadedFile::fake()->image('carita.png', 64, 64)])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $school->run(fn () => AnoLectivo::firstOrFail()->update(['estado' => 'planificado', 'siee' => null]));
        $this->deleteJson($base.'/config/escalas/'.$imageScale.'?opaque=1')->assertOk()->assertHeader('X-Notification-Record');
        foreach ($tokens as $path => $token) {
            $this->deleteJson($base.'/config/'.$path.'/'.$token.'?opaque=1')->assertOk()->assertHeader('X-Notification-Record');
        }
        $first = $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 24);
        $rows = array_merge($first->json('data'), $this->getJson($base.'/notification-records?page=2')->assertOk()->json('data'));
        foreach ($resources as $resource) {
            foreach (['store', 'update', 'destroy'] as $action) {
                $this->assertContains('parameters.'.$resource['module'].'.'.$action, array_column($rows, 'event'));
            }
        }
        $this->assertContains('parameters.options.save', array_column($rows, 'event'));
        $this->assertContains('parameters.options.upload', array_column($rows, 'event'));
        $this->assertCount(7, array_filter($rows, fn ($row) => $row['variant'] === 'danger'));
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 24);
    }

    public function test_study_plan_assignments_and_timetable_results_are_recorded(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Plan de prueba', 'slug' => 'plan-notificaciones-'.uniqid(),
            'rector_email' => 'rector@plan.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $yearToken, $groupToken, $teacherToken] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 4, 'estado' => 'planificado']);
            $campus = Sede::create(['nombre' => 'Principal', 'estado' => 'activa']);
            $shift = Jornada::create(['ano_lectivo_id' => $year->id, 'sede_id' => $campus->id,
                'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id,
                'nombre' => 'Primaria', 'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id,
                'nombre' => 'Primero', 'estado' => 'activo']);
            $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id,
                'jornada_id' => $shift->id, 'sede_id' => $campus->id, 'nombre' => '01A', 'estado' => 'activo']);
            $teacher = User::create(['name' => 'Docente de prueba', 'email' => 'docente@plan.test',
                'password' => bcrypt('Temporary1!abc'), 'role' => 'docente', 'status' => 'active', 'must_change_password' => false]);
            $teacher->assignRole('docente');

            return [$rector->createToken('test')->plainTextToken, OpaqueUrlToken::for('ano-lectivo', $year->id),
                OpaqueUrlToken::for('grupo', $group->id), OpaqueUrlToken::for('usuario', $teacher->id)];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api';
        $annual = ['ano_lectivo_token' => $yearToken];
        $area = $this->postJson($base.'/plan-estudios/areas?opaque=1', $annual + ['nombre' => 'Ciencias'])
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $this->postJson($base.'/plan-estudios/areas?opaque=1', $annual + ['nombre' => ''])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($base.'/plan-estudios/areas/'.$area.'?opaque=1', $annual + ['nombre' => 'Ciencias naturales'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $subjectInput = $annual + ['area_token' => $area, 'nivel_token' => null, 'nombre' => 'Biología', 'intensidad_horaria' => 4];
        $subject = $this->postJson($base.'/plan-estudios/materias?opaque=1', $subjectInput)
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $this->postJson($base.'/plan-estudios/materias?opaque=1', [...$subjectInput, 'intensidad_horaria' => 0])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->putJson($base.'/plan-estudios/materias/'.$subject.'?opaque=1', $subjectInput)
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->deleteJson($base.'/plan-estudios/areas/'.$area.'?opaque=1&ano_lectivo_token='.$yearToken)
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $school->run(function () {
            $group = Grupo::firstOrFail();
            $subject = Materia::firstOrFail();
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $group->ano_lectivo_id,
                'grado_id' => $group->grado_id, 'materia_id' => $subject->id, 'peso_area' => 1]);
        });
        $assignmentInput = $annual + ['grupo_token' => $groupToken, 'materia_token' => $subject, 'docente_token' => $teacherToken];
        $assignment = $this->postJson($base.'/asignaciones?opaque=1', $assignmentInput)
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $this->putJson($base.'/asignaciones/'.$assignment.'?opaque=1', $assignmentInput)
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->postJson($base.'/asignaciones/grupo?opaque=1', $assignmentInput)
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->postJson($base.'/asignaciones?opaque=1', $annual)
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $sessionInput = ['grupo_token' => $groupToken, 'materia_token' => $subject, 'docente_token' => $teacherToken,
            'dia' => 'lunes', 'hora_inicio' => '07:00', 'hora_fin' => '08:00'];
        $session = $this->postJson($base.'/horarios?opaque=1', $sessionInput)
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $copy = $this->postJson($base.'/horarios?opaque=1', [...$sessionInput, 'dia' => 'miercoles'])
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.url_token');
        $this->putJson($base.'/horarios/'.$session.'?opaque=1', [...$sessionInput, 'dia' => 'martes'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->postJson($base.'/horarios?opaque=1', [...$sessionInput, 'dia' => 'martes'])
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        $this->deleteJson($base.'/asignaciones/'.$assignment.'?opaque=1')
            ->assertUnprocessable()->assertHeader('X-Notification-Record');
        foreach ([$session, $copy] as $token) {
            $this->deleteJson($base.'/horarios/'.$token.'?opaque=1')->assertOk()->assertHeader('X-Notification-Record');
        }
        $this->deleteJson($base.'/asignaciones/'.$assignment.'?opaque=1')->assertOk()->assertHeader('X-Notification-Record');
        $this->deleteJson($base.'/plan-estudios/materias/'.$subject.'?opaque=1&ano_lectivo_token='.$yearToken)
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->deleteJson($base.'/plan-estudios/areas/'.$area.'?opaque=1&ano_lectivo_token='.$yearToken)
            ->assertOk()->assertHeader('X-Notification-Record');
        $first = $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 21);
        $rows = array_merge($first->json('data'), $this->getJson($base.'/notification-records?page=2')->assertOk()->json('data'));
        foreach (['area', 'materia', 'assignment', 'schedule'] as $module) {
            foreach (['store', 'update', 'destroy'] as $action) {
                $this->assertContains('plan.'.$module.'.'.$action, array_column($rows, 'event'));
            }
        }
        $this->assertContains('plan.assignment.assignGroup', array_column($rows, 'event'));
        $this->assertCount(6, array_filter($rows, fn ($row) => $row['variant'] === 'danger'));
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 21);
    }

    public function test_structure_crud_failures_and_group_director_are_recorded(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Estructura de prueba', 'slug' => 'estructura-notificaciones-'.uniqid(),
            'rector_email' => 'rector@structure.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $yearToken, $campusToken, $teacherToken] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create([
                'nombre' => '2028', 'tipo_calendario' => 'A', 'fecha_inicio' => '2028-01-01',
                'fecha_fin' => '2028-12-31', 'num_periodos' => 4, 'estado' => 'planificado',
            ]);
            $campus = Sede::create(['nombre' => 'Principal', 'estado' => 'activa']);
            $teacher = User::create(['name' => 'Docente de prueba', 'email' => 'docente@structure.test',
                'password' => bcrypt('Temporary1!abc'), 'role' => 'docente', 'status' => 'active', 'must_change_password' => false]);
            $teacher->assignRole('docente');

            return [$rector->createToken('test')->plainTextToken,
                OpaqueUrlToken::for('ano-lectivo', $year->id),
                OpaqueUrlToken::for('sede', $campus->id),
                OpaqueUrlToken::for('usuario', $teacher->id)];
        });
        $this->withToken($bearer);
        $base = 'http://'.$school->slug.'.localhost/api';
        $resources = [
            'jornadas' => ['module' => 'jornada', 'input' => ['sede_token' => $campusToken,
                'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '12:00']],
            'niveles' => ['module' => 'nivel', 'input' => ['nivel_educativo' => 'basica_primaria', 'nombre' => 'Primaria']],
            'grados' => ['module' => 'grado', 'input' => ['nombre' => 'Primero', 'codigo' => '01']],
            'grupos' => ['module' => 'grupo', 'input' => ['nombre' => 'A', 'cupo_maximo' => 30]],
            'bloques-horarios' => ['module' => 'bloque', 'input' => ['nombre' => 'Primera hora', 'hora_inicio' => '07:00', 'hora_fin' => '08:00']],
            'espacios-fisicos' => ['module' => 'espacio', 'input' => ['nombre' => 'Aula 1', 'tipo' => 'aula', 'sede_token' => $campusToken]],
        ];
        $tokens = [];
        foreach ($resources as $path => &$resource) {
            $input = ['ano_lectivo_token' => $yearToken, ...$resource['input']];
            if ($path === 'grados') {
                $input['nivel_token'] = $tokens['niveles'];
            } elseif ($path === 'grupos') {
                $input['grado_token'] = $tokens['grados'];
                $input['jornada_token'] = $tokens['jornadas'];
            } elseif ($path === 'bloques-horarios') {
                $input['jornada_token'] = $tokens['jornadas'];
            }
            $resource['input'] = $input;
            $created = $this->postJson($base.'/estructura/'.$path.'?opaque=1', $input)
                ->assertCreated()->assertHeader('X-Notification-Record');
            $tokens[$path] = $created->json('data.url_token');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $tokens[$path]);
            $this->putJson($base.'/estructura/'.$path.'/'.$tokens[$path].'?opaque=1', $input)
                ->assertOk()->assertHeader('X-Notification-Record');
            $this->postJson($base.'/estructura/'.$path.'?opaque=1', [...$input, 'nombre' => ''])
                ->assertUnprocessable()->assertHeader('X-Notification-Record');
        }
        unset($resource);
        $directorUrl = $base.'/estructura/grupos/'.$tokens['grupos'].'/director?opaque=1';
        $this->putJson($directorUrl, ['docente_token' => $teacherToken, 'version' => 0])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($directorUrl, ['docente_token' => null, 'version' => 0, 'motivo' => 'Cambio de prueba'])
            ->assertStatus(409)->assertHeader('X-Notification-Record');
        foreach (array_reverse(array_keys($resources)) as $path) {
            $this->deleteJson($base.'/estructura/'.$path.'/'.$tokens[$path].'?opaque=1&ano_lectivo_token='.$yearToken)
                ->assertOk()->assertHeader('X-Notification-Record');
        }
        $first = $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 26);
        $second = $this->getJson($base.'/notification-records?page=2')->assertOk();
        $rows = array_merge($first->json('data'), $second->json('data'));
        foreach ($resources as $resource) {
            foreach (['store', 'update', 'destroy'] as $action) {
                $this->assertContains('structure.'.$resource['module'].'.'.$action, array_column($rows, 'event'));
            }
        }
        $this->assertCount(7, array_filter($rows, fn ($row) => $row['variant'] === 'danger'));
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 26);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) config('database.connections.pgsql.database');
        if (config('database.default') !== 'pgsql'
            || ! preg_match('/^codex_notifications_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('Use scripts/test-notifications-postgres.php with disposable PostgreSQL databases.');
        }
        $this->assertSame($database, DB::selectOne('select current_database() as name')->name);
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 10)
        );
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        parent::tearDown();
    }

    public function test_action_results_persist_and_are_private_to_account_and_school(): void
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Notificaciones de prueba', 'slug' => 'notificaciones-'.uniqid(),
            'rector_email' => 'rector@notification.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$bearer, $otherBearer] = $school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $other = User::create(['name' => 'Otro usuario', 'email' => 'otro@notification.test',
                'password' => bcrypt('Temporary1!abc'), 'role' => 'docente', 'status' => 'active', 'must_change_password' => false]);

            return [$rector->createToken('test')->plainTextToken, $other->createToken('test')->plainTextToken];
        });
        $base = 'http://'.$school->slug.'.localhost/api';
        $this->withToken($bearer);
        $input = ['nombre' => '2028', 'tipo_calendario' => 'A', 'fecha_inicio' => '2028-01-01',
            'fecha_fin' => '2028-12-31', 'num_periodos' => 4, 'periodo_sumatorio' => false];
        $created = $this->postJson($base.'/anos-lectivos?opaque=1', $input)->assertCreated()
            ->assertHeader('X-Notification-Record');
        $token = $created->json('data.url_token');
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'year.store')->assertJsonPath('data.0.subject', '2028')
            ->assertJsonPath('data.0.variant', 'success')->assertJsonMissingPath('data.0.user_id')
            ->assertJsonMissingPath('data.0.id');
        $this->postJson($base.'/anos-lectivos?opaque=1', $input)->assertUnprocessable()
            ->assertHeader('X-Notification-Record');
        $rows = $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertContains('danger', array_column($rows->json('data'), 'variant'));
        $this->putJson($base.'/anos-lectivos/'.$token.'?opaque=1', $input)
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->deleteJson($base.'/anos-lectivos/'.$token.'?opaque=1')->assertForbidden()
            ->assertHeader('X-Notification-Record');
        // Reading and reloading never create additional notification records.
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson($base.'/notification-records')->assertOk()->assertJsonPath('meta.total', 4);
        $school->run(function () {
            $owner = User::where('role', 'rector')->firstOrFail();
            for ($index = 0; $index < 25; $index++) {
                DB::table('user_notification_records')->insert([
                    'url_token' => Str::random(24), 'user_id' => $owner->getKey(),
                    'event' => 'year.update', 'variant' => 'updated', 'subject' => '2028',
                    'created_at' => now()->addSeconds($index),
                ]);
            }
        });
        $firstPage = $this->getJson($base.'/notification-records')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 29)->assertJsonPath('meta.last_page', 2);
        $secondPage = $this->getJson($base.'/notification-records?page=2')->assertOk()->assertJsonCount(9, 'data');
        $this->assertEmpty(array_intersect(array_column($firstPage->json('data'), 'token'), array_column($secondPage->json('data'), 'token')));
        $this->getJson($base.'/notification-records?page=0')->assertUnprocessable();
        $this->withToken($otherBearer);
        app('auth')->forgetGuards();
        $this->getJson($base.'/notification-records?user_id=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/notification-records/1')->assertNotFound();
        tenancy()->end();
        ['tenant' => $otherSchool] = app(TenantProvisioner::class)->provision([
            'name' => 'Otro colegio', 'slug' => 'otro-notificaciones-'.uniqid(), 'rector_email' => 'otro@school.test',
        ]);
        $otherSchool->update(['status' => Tenant::STATUS_ACTIVE]);
        $token2 = $otherSchool->run(function () {
            $user = User::where('role', 'rector')->firstOrFail();
            $user->update(['must_change_password' => false]);

            return $user->createToken('test')->plainTextToken;
        });
        $this->withToken($token2);
        app('auth')->forgetGuards();
        $this->getJson('http://'.$otherSchool->slug.'.localhost/api/notification-records')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders(['Authorization' => '']);
        app('auth')->forgetGuards();
        $this->getJson($base.'/notification-records')->assertUnauthorized();
    }
}
