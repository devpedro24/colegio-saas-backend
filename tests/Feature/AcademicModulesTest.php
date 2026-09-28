<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Controllers\Api\Academico\EventoController;
use App\Http\Controllers\Api\Academico\HorarioController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Evento;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventAccess;
use App\Services\GradebookService;
use App\Services\HorarioService;
use App\Services\SieeConfiguration;
use App\Support\Sedes\SedeLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AcademicModulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $school;

    private User $teacher;

    private User $rector;

    private AnoLectivo $year;

    private Grupo $group;

    private Materia $subject;

    private AsignacionDocente $assignment;

    private BloqueHorario $block;

    protected function setUp(): void
    {
        parent::setUp();
        // Conexión tenant real separada de la central, ambas SQLite en memoria.
        config(['tenancy.bootstrappers' => [], 'database.connections.academic_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'academic-test', 'name' => 'Colegio', 'slug' => 'academic-test', 'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active']));
        DB::setDefaultConnection('academic_test');
        Artisan::call('migrate', ['--database' => 'academic_test', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize($this->school);
        Role::findOrCreate('docente', 'web');
        Role::findOrCreate('rector', 'web');
        $this->teacher = User::create(['name' => 'Docente', 'email' => 'teacher@test.test', 'password' => 'TeacherPassword123', 'role' => 'docente', 'status' => 'active']);
        $this->teacher->assignRole('docente');
        $this->rector = User::create(['name' => 'Rector', 'email' => 'rector@test.test', 'password' => 'RectorPassword123', 'role' => 'rector', 'status' => 'active']);
        $this->rector->assignRole('rector');
        $this->year = AnoLectivo::create(['nombre' => '2026', 'tipo_calendario' => 'A', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'num_periodos' => 3, 'estado' => 'en_curso']);
        $sede = Sede::create(['nombre' => 'Norte', 'estado' => 'activa']);
        $journey = Jornada::create(['sede_id' => $sede->id, 'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '13:00', 'estado' => 'activa']);
        $level = Nivel::create(['nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $grade = Grado::create(['nivel_id' => $level->id, 'nombre' => 'Primero', 'codigo' => '01', 'estado' => 'activo']);
        $this->group = Grupo::create(['grado_id' => $grade->id, 'ano_lectivo_id' => $this->year->id, 'nombre' => 'A', 'jornada_id' => $journey->id, 'sede_id' => $sede->id, 'estado' => 'activo']);
        $area = Area::create(['nombre' => 'Ciencias', 'estado' => 'activo']);
        $this->subject = Materia::create(['nombre' => 'Biología', 'area_id' => $area->id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
        $this->assignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id, 'docente_id' => $this->teacher->id]);
        $this->block = BloqueHorario::create(['jornada_id' => $journey->id, 'nombre' => 'Primera', 'hora_inicio' => '08:00', 'hora_fin' => '09:00', 'estado' => 'activo', 'es_descanso' => false]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::setDefaultConnection('sqlite');
        DB::purge('academic_test');
        parent::tearDown();
    }

    public function test_grades_are_grouped_by_first_creation_in_each_level(): void
    {
        Grado::query()->delete();
        $secundaria = Nivel::create(['nombre' => 'Secundaria', 'nivel_educativo' => 'secundaria', 'estado' => 'activo']);
        $primaria = Nivel::where('nivel_educativo', 'primaria')->firstOrFail();
        Grado::create(['nivel_id' => $secundaria->id, 'nombre' => 'Sexto', 'codigo' => '06', 'estado' => 'activo']);
        Grado::create(['nivel_id' => $primaria->id, 'nombre' => 'Cuarto', 'codigo' => '04', 'estado' => 'activo']);
        Grado::create(['nivel_id' => $secundaria->id, 'nombre' => 'Séptimo', 'codigo' => '07', 'estado' => 'activo']);

        $niveles = app(NivelController::class)->index()->getData(true)['data'];
        $grados = app(GradoController::class)->index(Request::create('/'))->getData(true)['data'];

        $this->assertSame(['Primaria', 'Secundaria'], array_column($niveles, 'nombre'));
        $this->assertSame(['Sexto', 'Séptimo', 'Cuarto'], array_column($grados, 'nombre'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('niveles', 'orden'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('grados', 'orden'));
    }

    public function test_blocks_accept_string_journey_id_and_report_overlaps_in_spanish(): void
    {
        $controller = app(BloqueHorarioController::class);
        $journeyId = (string) $this->block->jornada_id;
        $request = static function (string $method, array $input): Request {
            $request = Request::create('/', $method, $input);
            $request->setUserResolver(fn () => User::where('email', 'rector@test.test')->firstOrFail());

            return $request;
        };

        $controller->update($request('PUT', ['jornada_id' => $journeyId, 'hora_inicio' => '08:00', 'hora_fin' => '09:00']), $this->block->id);
        $created = $controller->store($request('POST', ['jornada_id' => $journeyId, 'nombre' => 'Entrada', 'hora_inicio' => '07:00', 'hora_fin' => '07:45']));
        $this->assertSame(201, $created->getStatusCode());

        try {
            $controller->store($request('POST', ['jornada_id' => $journeyId, 'nombre' => 'Cruce', 'hora_inicio' => '07:30', 'hora_fin' => '08:15']));
            $this->fail('Un bloque superpuesto no debe guardarse.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('se cruza', $exception->errors()['hora_inicio'][0]);
        }

        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('bloques_horarios', 'orden'));
    }

    public function test_schedule_persists_and_blocks_overlaps_between_distinct_time_slots(): void
    {
        $service = new HorarioService;
        $data = ['asignacion_id' => $this->assignment->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id, 'espacio_fisico_id' => null];
        $session = $service->guardar($data, $this->rector);
        $this->assertDatabaseHas('sesiones_horario', ['id' => $session->id]);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'sesion_horario', 'accion' => 'CREATE']);
        $overlap = $this->block->replicate();
        $overlap->nombre = 'Solapado';
        $overlap->hora_inicio = '08:30';
        $overlap->hora_fin = '09:30';
        $overlap->save();
        $this->expectException(ValidationException::class);
        $service->guardar([...$data, 'bloque_horario_id' => $overlap->id], $this->rector);
    }

    public function test_a_block_used_by_classes_cannot_change_its_time_or_be_deleted(): void
    {
        $session = (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $controller = app(BloqueHorarioController::class);
        $request = static function (string $method, array $input = []): Request {
            $request = Request::create('/', $method, $input);
            $request->setUserResolver(fn () => User::where('email', 'rector@test.test')->firstOrFail());

            return $request;
        };

        $renamed = $controller->update($request('PUT', ['nombre' => 'Primer bloque']), $this->block->id);
        $this->assertSame('Primer bloque', $renamed->getData(true)['data']['nombre']);

        try {
            $controller->update($request('PUT', ['hora_inicio' => '08:15', 'hora_fin' => '09:15']), $this->block->id);
            $this->fail('No debe cambiarse la hora de un bloque con clases.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('clases programadas', $exception->errors()['hora_inicio'][0]);
        }

        try {
            $controller->destroy($request('DELETE'), $this->block->id);
            $this->fail('No debe eliminarse un bloque con clases.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('clases programadas', $exception->getMessage());
        }

        $this->assertSame('08:00', substr($this->block->fresh()->hora_inicio, 0, 5));
        $this->assertSame($this->block->id, $session->fresh()->bloque_horario_id);

        (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'lunes',
            'hora_inicio' => '08:00',
            'hora_fin' => '09:00',
        ], $this->rector, $session);
        $deleted = $controller->destroy($request('DELETE'), $this->block->id);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertNull($session->fresh()->bloque_horario_id);
        $this->assertSame('08:00', substr($session->fresh()->hora_inicio, 0, 5));
    }

    public function test_schedule_can_use_custom_hours_and_switch_between_modes(): void
    {
        $service = new HorarioService;
        $free = ['asignacion_id' => $this->assignment->id, 'dia' => 'martes', 'hora_inicio' => '07:00', 'hora_fin' => '07:45', 'espacio_fisico_id' => null];
        $session = $service->guardar($free, $this->rector);
        $this->assertNull($session->bloque_horario_id);
        $this->assertSame('07:00', substr($session->fresh()->hora_inicio, 0, 5));

        $session = $service->guardar(['asignacion_id' => $this->assignment->id, 'dia' => 'martes', 'bloque_horario_id' => $this->block->id], $this->rector, $session);
        $this->assertSame($this->block->id, $session->bloque_horario_id);
        $this->assertNull($session->hora_inicio);

        $session = $service->guardar($free, $this->rector, $session);
        $this->assertNull($session->bloque_horario_id);
        $this->assertSame('07:00', substr($session->fresh()->hora_inicio, 0, 5));
    }

    public function test_custom_hours_cannot_cross_an_existing_block_class(): void
    {
        $service = new HorarioService;
        $service->guardar(['asignacion_id' => $this->assignment->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);

        try {
            $service->guardar(['asignacion_id' => $this->assignment->id, 'dia' => 'lunes', 'hora_inicio' => '08:30', 'hora_fin' => '09:15'], $this->rector);
            $this->fail('Una clase con horas libres no puede cruzarse con otra clase.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('grupo', $exception->errors()['horario'][0]);
        }
    }

    public function test_schedule_endpoint_accepts_custom_hours_without_a_block(): void
    {
        $request = Request::create('/api/horarios', 'POST', [
            'asignacion_id' => (string) $this->assignment->id,
            'dia' => 'miercoles',
            'hora_inicio' => '07:10',
            'hora_fin' => '07:50',
        ]);
        $request->setUserResolver(fn () => $this->rector);

        $response = app(HorarioController::class)->guardar($request, new HorarioService);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertNull($response->getData(true)['data']['bloque_horario_id']);
        $this->assertDatabaseHas('sesiones_horario', [
            'asignacion_id' => $this->assignment->id,
            'dia' => 'miercoles',
            'hora_inicio' => '07:10',
            'hora_fin' => '07:50',
            'bloque_horario_id' => null,
        ]);
    }

    public function test_custom_schedule_works_when_the_journey_has_no_blocks(): void
    {
        $this->block->forceDelete();

        $session = (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'jueves',
            'hora_inicio' => '07:00',
            'hora_fin' => '07:35',
        ], $this->rector);

        $this->assertDatabaseCount('bloques_horarios', 0);
        $this->assertNull($session->bloque_horario_id);
        $this->assertSame('07:35', substr($session->hora_fin, 0, 5));
    }

    public function test_groups_in_the_same_journey_can_use_different_custom_times(): void
    {
        $preescolar = Nivel::create(['nombre' => 'Preescolar', 'nivel_educativo' => 'preescolar', 'estado' => 'activo']);
        $prejardin = Grado::create(['nivel_id' => $preescolar->id, 'nombre' => 'Prejardín', 'codigo' => 'PJ', 'estado' => 'activo']);
        $otherGroup = Grupo::create([
            'grado_id' => $prejardin->id,
            'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id,
            'sede_id' => $this->group->sede_id,
            'nombre' => 'A',
            'estado' => 'activo',
        ]);
        $otherTeacher = User::create(['name' => 'Otra docente', 'email' => 'other-teacher@test.test', 'password' => 'TeacherPassword123', 'role' => 'docente', 'status' => 'active']);
        $otherTeacher->assignRole('docente');
        $otherSubject = Materia::create(['nombre' => 'Lenguaje inicial', 'area_id' => $this->subject->area_id, 'nivel_id' => $preescolar->id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
        $otherAssignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $otherGroup->id, 'materia_id' => $otherSubject->id, 'docente_id' => $otherTeacher->id]);

        $service = new HorarioService;
        $first = $service->guardar(['asignacion_id' => $this->assignment->id, 'dia' => 'lunes', 'hora_inicio' => '07:00', 'hora_fin' => '07:45'], $this->rector);
        $second = $service->guardar(['asignacion_id' => $otherAssignment->id, 'dia' => 'lunes', 'hora_inicio' => '07:20', 'hora_fin' => '08:00'], $this->rector);

        $this->assertNull($first->bloque_horario_id);
        $this->assertNull($second->bloque_horario_id);
        $this->assertSame('07:20', substr($second->hora_inicio, 0, 5));
    }

    public function test_schedule_can_be_created_without_teacher_or_assignment_then_edited(): void
    {
        $service = new HorarioService;
        $class = $service->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => null,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $this->assertNull($class->asignacion_id);
        $this->assertNull($class->docente_id);
        $this->assertSame($this->group->id, $class->grupo_id);
        $this->assertSame($this->subject->id, $class->materia_id);

        $class = $service->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id,
            'dia' => 'lunes',
            'hora_inicio' => '07:00',
            'hora_fin' => '07:45',
        ], $this->rector, $class);
        $this->assertSame($this->teacher->id, $class->docente_id);
        $this->assertNull($class->bloque_horario_id);
        $this->assertSame('07:00', substr($class->hora_inicio, 0, 5));
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'sesion_horario', 'accion' => 'UPDATE']);
    }

    public function test_schedule_endpoint_creates_class_when_no_assignments_or_teachers_exist(): void
    {
        $this->assignment->forceDelete();
        $this->teacher->forceDelete();
        $request = Request::create('/api/horarios', 'POST', [
            'grupo_id' => (string) $this->group->id,
            'materia_id' => (string) $this->subject->id,
            'dia' => 'martes',
            'bloque_horario_id' => (string) $this->block->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);

        $response = app(HorarioController::class)->guardar($request, new HorarioService);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertNull($response->getData(true)['data']['docente_id']);
        $this->assertNull($response->getData(true)['data']['asignacion_id']);
        $this->assertDatabaseHas('sesiones_horario', ['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id]);
    }

    public function test_schedule_migration_backfills_legacy_assignment_fields(): void
    {
        $class = (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        DB::table('sesiones_horario')->where('id', $class->id)->update([
            'grupo_id' => null, 'materia_id' => null, 'docente_id' => null,
        ]);
        $migration = require database_path('migrations/tenant/2026_09_28_000004_decouple_schedules_from_teacher_assignments.php');
        $migration->up();
        $this->assertDatabaseHas('sesiones_horario', [
            'id' => $class->id,
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id,
        ]);
    }

    public function test_direct_schedule_is_visible_to_enrolled_students_and_assigned_teacher_only(): void
    {
        [, $enrollment] = $this->gradeFixture();
        $class = (new HorarioService)->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => null,
            'dia' => 'viernes',
            'hora_inicio' => '07:00',
            'hora_fin' => '07:45',
        ], $this->rector);
        $request = Request::create('/api/horarios');
        $request->setUserResolver(fn () => $enrollment->estudiante);
        $this->assertSame([$class->id], array_column(app(HorarioController::class)->index($request)->getData(true)['data']['sesiones'], 'id'));

        $request->setUserResolver(fn () => $this->teacher);
        $this->assertSame([], app(HorarioController::class)->index($request)->getData(true)['data']['sesiones']);
        (new HorarioService)->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id,
            'dia' => 'viernes',
            'hora_inicio' => '07:00',
            'hora_fin' => '07:45',
        ], $this->rector, $class);
        $this->assertSame([$class->id], array_column(app(HorarioController::class)->index($request)->getData(true)['data']['sesiones'], 'id'));
    }

    public function test_direct_schedule_rejects_overlapping_group_classes_even_without_teacher(): void
    {
        $service = new HorarioService;
        $first = ['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id, 'docente_id' => null, 'dia' => 'lunes'];
        $service->guardar([...$first, 'bloque_horario_id' => $this->block->id], $this->rector);
        try {
            $service->guardar([...$first, 'hora_inicio' => '08:30', 'hora_fin' => '09:15'], $this->rector);
            $this->fail('El mismo grupo no puede tener dos clases al tiempo.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('grupo', $exception->errors()['horario'][0]);
        }
    }

    public function test_legacy_schedule_remains_when_teacher_assignment_is_removed(): void
    {
        $class = (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $request = Request::create('/api/asignaciones/'.$this->assignment->id, 'DELETE');
        $request->setUserResolver(fn () => $this->rector);
        $this->assertSame(200, app(HorarioController::class)->desasignar($request, $this->assignment->id)->getStatusCode());
        $this->assertNull($class->fresh()->asignacion_id);
        $this->assertSame($this->teacher->id, $class->fresh()->docente_id);
        $this->assertSame($this->group->id, $class->fresh()->grupo_id);
    }

    public function test_a_shared_block_can_be_used_by_different_groups_without_teachers(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id,
            'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id,
            'sede_id' => $this->group->sede_id,
            'nombre' => 'B',
            'estado' => 'activo',
        ]);
        $service = new HorarioService;
        $first = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $second = $service->guardar(['grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $this->assertSame($first->bloque_horario_id, $second->bloque_horario_id);
        $this->assertNotSame($first->grupo_id, $second->grupo_id);
    }

    public function test_teacher_cannot_publish_schoolwide_even_with_expanded_policy(): void
    {
        DB::table('configuracion_eventos')->insert(['id' => 1, 'docentes_cualquier_grupo' => true]);
        $this->expectException(HttpException::class);
        (new EventAccess)->authorizeWrite($this->teacher, ['institucional' => true, 'grupo_ids' => []]);
    }

    public function test_event_attachment_rejects_unsupported_files_as_validation_errors(): void
    {
        $event = Evento::create(['titulo' => 'Evento', 'descripcion' => 'Actividad', 'fecha' => '2026-09-25', 'created_by' => $this->rector->id, 'institucional' => true]);
        $request = Request::create('/eventos/'.$event->id.'/archivos', 'POST', [], [], ['file' => UploadedFile::fake()->create('script.exe', 1, 'application/x-msdownload')]);
        $request->setUserResolver(fn () => $this->rector);
        $response = app(EventoController::class)->archivo($request, $event->id);
        $this->assertSame(422, $response->status());
        $this->assertSame(0, DB::table('evento_archivos')->count());
    }

    public function test_event_attachment_is_stored_linked_audited_and_visible_to_its_author(): void
    {
        Storage::fake('tenant');
        $event = Evento::create(['titulo' => 'Evento', 'descripcion' => 'Actividad', 'fecha' => '2026-09-25', 'created_by' => $this->rector->id, 'institucional' => true]);
        $upload = UploadedFile::fake()->createWithContent('instrucciones.txt', 'Contenido de prueba del evento.');
        $request = Request::create('/eventos/'.$event->id.'/archivos', 'POST', [], [], ['file' => $upload]);
        $request->setUserResolver(fn () => $this->rector);
        $controller = app(EventoController::class);
        $response = $controller->archivo($request, $event->id);
        $this->assertSame(201, $response->status());
        $file = StoredFile::findOrFail($response->getData(true)['data']['id']);
        $this->assertSame('Contenido de prueba del evento.', Storage::disk('tenant')->get($file->path));
        $this->assertDatabaseHas('evento_archivos', ['evento_id' => $event->id, 'stored_file_id' => $file->id]);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'evento', 'accion' => 'UPLOAD', 'recurso_id' => (string) $event->id]);
        $detail = $controller->show($request, $event->id)->getData(true)['data'];
        $this->assertSame($file->id, $detail['archivos'][0]['id']);
        $this->assertStringContainsString('signature=', $detail['archivos'][0]['url']);
    }

    public function test_event_attachment_limit_is_checked_before_storing(): void
    {
        $event = Evento::create(['titulo' => 'Evento', 'descripcion' => 'Actividad', 'fecha' => '2026-09-25', 'created_by' => $this->rector->id, 'institucional' => true]);
        foreach (range(1, 10) as $fileId) {
            DB::table('evento_archivos')->insert(['evento_id' => $event->id, 'stored_file_id' => $fileId]);
        }
        $request = Request::create('/eventos/'.$event->id.'/archivos', 'POST', [], [], ['file' => UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(EventoController::class)->archivo($request, $event->id);
            $this->fail('The attachment limit must reject the upload.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(10, DB::table('evento_archivos')->count());
        }
    }

    public function test_teacher_can_publish_only_assigned_subjects_by_default(): void
    {
        $access = new EventAccess;
        $data = ['institucional' => false, 'grupo_ids' => [$this->group->id], 'materia_id' => $this->subject->id];
        $access->authorizeWrite($this->teacher, $data);
        $this->assertTrue(true);
        $this->expectException(HttpException::class);
        $access->authorizeWrite($this->teacher, [...$data, 'materia_id' => 999]);
    }

    public function test_unrelated_teacher_cannot_see_a_group_event(): void
    {
        $event = Evento::create(['titulo' => 'Clase privada', 'descripcion' => 'Actividad', 'fecha' => '2026-09-25', 'created_by' => $this->teacher->id, 'institucional' => false]);
        $event->grupos()->attach($this->group->id);
        $other = User::create(['name' => 'Otro', 'email' => 'other@test.test', 'password' => 'Password12345', 'status' => 'active']);
        $other->assignRole('docente');
        $this->assertFalse((new EventAccess)->visible($other)->whereKey($event->id)->exists());
        $this->assertTrue((new EventAccess)->visible($this->teacher)->whereKey($event->id)->exists());
    }

    public function test_custom_plan_limits_and_unknown_plan_is_not_unlimited(): void
    {
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'max_sedes' => 1]);
        $this->assertTrue(SedeLimits::alLimite());
        Plan::where('key', 'esencial')->update(['max_sedes' => 5]);
        $this->assertFalse(SedeLimits::alLimite());
        $this->school->update(['plan' => 'missing']);
        $this->expectException(ValidationException::class);
        SedeLimits::maxSedes();
    }

    public function test_subjects_without_areas_are_supported(): void
    {
        $subject = Materia::create(['nombre' => 'Inglés', 'area_id' => null, 'intensidad_horaria' => 3, 'estado' => 'activo']);
        $this->assertNull($subject->fresh()->area_id);
    }

    private function gradeFixture(): array
    {
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id, 'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'modo_asignatura' => 'SIMPLE_AVERAGE', 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Trimestre 1', 'orden' => 1, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        Role::findOrCreate('estudiante', 'web');
        $student = User::create(['name' => 'Estudiante', 'email' => 'student@test.test', 'password' => 'Student123456', 'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id, 'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $component = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id, 'periodo_id' => $period->id, 'nombre' => 'Talleres', 'modo' => 'SIMPLE_AVERAGE']);
        $activity = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Taller 1', 'fecha' => '2026-02-05']);

        return [$period, $enrollment, $activity];
    }

    public function test_grade_updates_are_exact_audited_and_optimistically_locked(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $book = new GradebookService;
        $row = ['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '2.99999999', 'version' => 0, 'motivo' => 'Registro inicial'];
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [$row]);
        $result = $book->subjectResult($this->assignment, $enrollment, $period, app(SieeConfiguration::class)->resolve($this->year));
        $this->assertSame('3.0', $result['display_value']);
        $this->assertFalse($result['aprobado']); // Se compara la nota sin redondear.
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'calificacion', 'accion' => 'CREATE']);
        try {
            $book->saveGrades($this->teacher, $this->assignment, $period->id, [[...$row, 'valor' => '4']]);
            $this->fail('Debió rechazar una versión desactualizada.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('2.99999999', Calificacion::first()->valor);
    }

    public function test_incomplete_grades_remain_pending_and_annual_needs_all_periods(): void
    {
        [$period, $enrollment] = $this->gradeFixture();
        $report = (new GradebookService)->report($enrollment);
        $this->assertSame('VISTA_PREVIA', $report['tipo']);
        $this->assertSame('pendiente', $report['asignaturas'][0]['periodos'][0]['estado']);
        $this->assertSame('pendiente', $report['asignaturas'][0]['anual']['estado']);
        $this->assertArrayNotHasKey('display_value', $report['asignaturas'][0]['periodos'][0]);
    }

    public function test_closed_periods_block_grade_writes(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $period->update(['estado' => 'cerrado']);
        try {
            (new GradebookService)->saveGrades($this->teacher, $this->assignment, $period->id, [['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '4', 'version' => 0]]);
            $this->fail('No debe aceptar notas en un período cerrado.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertDatabaseCount('calificaciones', 0);
    }

    public function test_area_result_cannot_ignore_unassigned_curriculum_subjects(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['siee' => [...$this->year->siee, 'usar_areas' => true]]);
        $missing = Materia::create(['nombre' => 'Ecología', 'area_id' => $this->subject->area_id, 'intensidad_horaria' => 2, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id, 'materia_id' => $missing->id, 'area_id' => $this->subject->area_id]);
        $book = new GradebookService;
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '4', 'version' => 0]]);
        $report = $book->report($enrollment);
        $this->assertCount(2, $report['asignaturas']);
        $this->assertSame('pendiente', $report['areas'][0]['periodos'][0]['estado']);
        $this->assertNotEmpty($report['advertencias']);
    }

    public function test_wrong_teacher_and_other_students_cannot_read_gradebooks_or_reports(): void
    {
        [$period, $enrollment] = $this->gradeFixture();
        $other = User::create(['name' => 'Otro', 'email' => 'outsider@test.test', 'password' => 'Password12345', 'status' => 'active']);
        $other->assignRole('docente');
        $request = Request::create('/api/evaluacion/boletines/'.$enrollment->id);
        $request->setUserResolver(fn () => $other);
        $controller = app(EvaluacionController::class);
        foreach (['planilla', 'boletin'] as $action) {
            try {
                $action === 'planilla' ? $controller->planilla($request, $this->assignment->id, $period->id) : $controller->boletin($request, $enrollment->id);
                $this->fail('Se permitió acceso ajeno.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $request->setUserResolver(fn () => $enrollment->estudiante);
        $this->assertSame(200, $controller->boletin($request, $enrollment->id)->status());
    }

    public function test_grade_batch_rolls_back_if_any_grade_exceeds_scale(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $second = $activity->replicate();
        $second->nombre = 'Taller 2';
        $second->save();
        $row = ['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '4', 'version' => 0];
        try {
            (new GradebookService)->saveGrades($this->teacher, $this->assignment, $period->id, [$row, [...$row, 'actividad_id' => $second->id, 'valor' => '5.01']]);
            $this->fail('No debe aceptar una nota fuera de escala.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertDatabaseCount('calificaciones', 0);
        $this->assertDatabaseMissing('audit_logs', ['recurso' => 'calificacion']);
    }

    public function test_student_schedule_and_event_scope_follow_enrollment(): void
    {
        [, $enrollment] = $this->gradeFixture();
        $request = Request::create('/api/horarios');
        $request->setUserResolver(fn () => $enrollment->estudiante);
        $data = app(HorarioController::class)->index($request)->getData(true)['data'];
        $this->assertFalse($data['can_manage']);
        $this->assertSame([$this->assignment->id], array_column($data['asignaciones'], 'id'));
        $this->assertSame([$this->group->id], (new EventAccess)->groupIds($enrollment->estudiante));
    }
}
