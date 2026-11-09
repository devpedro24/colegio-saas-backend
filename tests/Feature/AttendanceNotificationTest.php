<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
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
use App\Models\Academico\Sede;
use App\Models\Academico\SesionHorario;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttendanceNotifications;
use App\Services\AulaNotifications;
use App\Services\SieeConfiguration;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\DatabaseConfig;
use Tests\TestCase;

class AttendanceNotificationTest extends TestCase
{
    private ?Tenant $gradebookSchool = null;

    private string $gradebookActor = 'rector';

    private function fixture(): array
    {
        $f = $this->gradebookFixture();
        $extra = $f['school']->run(function () {
            $a = AsignacionDocente::firstOrFail();
            $session = SesionHorario::create(['ano_lectivo_id' => $a->ano_lectivo_id,
                'asignacion_id' => $a->id, 'grupo_id' => $a->grupo_id, 'materia_id' => $a->materia_id,
                'docente_id' => $a->docente_id, 'dia' => 'martes', 'hora_inicio' => '07:00', 'hora_fin' => '07:50']);
            $peer = User::create(['name' => 'Otro estudiante', 'email' => 'peer@attendance.test', 'password' => bcrypt('Temporary1!abc'),
                'role' => 'estudiante', 'status' => 'active', 'must_change_password' => false]);
            $peer->assignRole('estudiante');
            Matricula::create(['ano_lectivo_id' => $a->ano_lectivo_id, 'grupo_id' => $a->grupo_id,
                'estudiante_id' => $peer->id, 'estado' => 'activa']);

            return ['session' => OpaqueUrlToken::for('sesion-horario', $session->id), 'peerBearer' => $peer->createToken('test')->plainTextToken,
                'peerEnrollment' => OpaqueUrlToken::for('matricula', Matricula::where('estudiante_id', $peer->id)->value('id'))];
        });

        return [...$f, ...$extra, 'bearers' => [...$f['bearers'], 'peer' => $extra['peerBearer']]];
    }

    private function saveMarks(array $f, string $status = 'ausente', int $version = 0): TestResponse
    {
        $this->gradebookAuthenticate($f, 'docente');

        return $this->putJson($f['base'].'/asistencias', ['asignacion_token' => $f['assignmentToken'], 'sesion_token' => $f['session'],
            'fecha' => '2028-02-01', 'version' => $version, 'marcas' => [
                ['matricula_token' => $f['enrollmentToken'], 'estado' => $status],
                ['matricula_token' => $f['peerEnrollment'], 'estado' => 'presente']]]);
    }

    private function news(array $f, string $role, int $total): TestResponse
    {
        $this->gradebookAuthenticate($f, $role);

        return $this->getJson($f['base'].'/notification-updates')->assertOk()->assertJsonPath('meta.total', $total)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    private function requestAbsence(array $f, string $role): string
    {
        $this->gradebookAuthenticate($f, $role);

        return $this->postJson($f['base'].'/asistencias/solicitudes', ['matricula_token' => $f['enrollmentToken'],
            'asignacion_token' => $f['assignmentToken'], 'motivo' => 'Motivo privado que no debe aparecer en novedades',
            'marcas' => [['sesion_token' => $f['session'], 'fecha' => '2028-02-01']]])
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data.token');
    }

    public function test_absence_is_private_persistent_and_present_and_unchanged_saves_send_no_notice(): void
    {
        $f = $this->fixture();
        $this->saveMarks($f)->assertOk()->assertHeader('X-Notification-Record');
        $feed = $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.event', 'attendance.absent')
            ->assertJsonPath('data.0.attendance_date', '2028-02-01')->assertJsonPath('data.0.attendance_time', '07:00–07:50')
            ->assertJsonPath('data.0.url', '/academico/asistencia');
        foreach (['matricula_id', 'user_id', 'asistencia_marca_id', 'asistencia_solicitud_id', 'Motivo privado', 'Estudiante confidencial'] as $secret) {
            $this->assertStringNotContainsString($secret, $feed->getContent());
        }
        $this->news($f, 'peer', 0);
        $this->news($f, 'rector', 0);
        $this->news($f, 'docente', 0);
        $this->saveMarks($f, 'ausente', 1)->assertOk()->assertJsonPath('data.version', 1);
        $this->news($f, 'estudiante', 1);
        $this->saveMarks($f, 'presente', 1)->assertOk();
        $this->news($f, 'estudiante', 0);
        $this->saveMarks($f, 'presente', 1)->assertConflict()->assertHeader('X-Notification-Record');
        $f['school']->run(function () {
            $this->assertSame(1, DB::table('user_notification_updates')->count());
            $this->assertSame(4, DB::table('user_notification_records')->where('event', 'attendance.sheet.save')->count());
            $this->assertSame('danger', DB::table('user_notification_records')->orderByDesc('created_at')->orderByDesc('url_token')->where('variant', 'danger')->value('variant'));
        });
        $this->news($f, 'estudiante', 0);
    }

    public function test_justification_workflow_notifies_only_owner_and_keeps_reasons_out_of_feed(): void
    {
        $f = $this->fixture();
        $this->saveMarks($f)->assertOk();
        $token = $this->requestAbsence($f, 'estudiante');
        $this->news($f, 'estudiante', 2);
        $this->gradebookAuthenticate($f, 'docente');
        $this->putJson($f['base'].'/asistencias/solicitudes/'.$token.'/revision-docente', ['decision' => 'remitir'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $this->gradebookAuthenticate($f, 'rector');
        $this->putJson($f['base'].'/asistencias/solicitudes/'.$token.'/resolucion', ['decision' => 'aprobar'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $feed = $this->news($f, 'estudiante', 4);
        $this->assertEqualsCanonicalizing(['attendance.absent', 'attendance.justification.requested',
            'attendance.justification.forwarded', 'attendance.justification.approved'], array_column($feed->json('data'), 'event'));
        $this->assertStringNotContainsString('Motivo privado', $feed->getContent());
        $this->news($f, 'peer', 0);
        $this->news($f, 'rector', 0);
        $this->gradebookAuthenticate($f, 'estudiante');
        $this->getJson($f['base'].'/asistencias/solicitudes?scope=propias')->assertOk()->assertJsonPath('data.solicitudes.0.estado', 'aprobada');
        $f['school']->run(fn () => $this->assertSame(1, DB::table('asistencia_marcas')->where('estado', 'justificada')->count()));
    }

    public function test_teacher_and_final_rejections_notify_and_failed_retries_do_not_duplicate(): void
    {
        $f = $this->fixture();
        $this->saveMarks($f)->assertOk();
        $token = $this->requestAbsence($f, 'estudiante');
        $this->gradebookAuthenticate($f, 'docente');
        $url = $f['base'].'/asistencias/solicitudes/'.$token.'/revision-docente';
        $this->putJson($url, ['decision' => 'rechazar', 'respuesta' => 'Respuesta muy privada'])->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($url, ['decision' => 'rechazar', 'respuesta' => 'Respuesta muy privada'])->assertConflict();
        $this->news($f, 'estudiante', 3);
        $token = $this->requestAbsence($f, 'docente');
        $this->gradebookAuthenticate($f, 'rector');
        $this->putJson($f['base'].'/asistencias/solicitudes/'.$token.'/resolucion', ['decision' => 'rechazar', 'respuesta' => 'Otra respuesta privada'])
            ->assertOk()->assertHeader('X-Notification-Record');
        $feed = $this->news($f, 'estudiante', 5);
        $this->assertSame(2, collect($feed->json('data'))->where('event', 'attendance.justification.rejected')->count());
        $this->assertStringNotContainsString('privada', $feed->getContent());
        $this->news($f, 'peer', 0);
    }

    public function test_plan_permissions_enrollment_and_other_tenants_are_enforced_for_attendance_updates(): void
    {
        $f = $this->fixture();
        $this->saveMarks($f)->assertOk();
        $f['school']->run(function () {
            $student = User::where('role', 'estudiante')->where('email', 'estudiante@gradebook.test')->firstOrFail();
            $student->syncRoles([]);
        });
        $this->gradebookAuthenticate($f, 'estudiante');
        $this->getJson($f['base'].'/notification-updates')->assertForbidden();
        $f['school']->run(function () {
            $student = User::where('email', 'estudiante@gradebook.test')->firstOrFail();
            $student->assignRole('estudiante');
            Matricula::where('estudiante_id', $student->id)->update(['estado' => 'retirada']);
        });
        $this->news($f, 'estudiante', 0);
        $other = $this->fixture();
        $this->news($other, 'estudiante', 0);
        $this->gradebookAuthenticate($other, 'estudiante');
        $this->getJson($f['base'].'/notification-updates')->assertUnauthorized();
    }

    public function test_notification_failure_rolls_back_attendance_and_successful_present_rows_only_record_actor(): void
    {
        $f = $this->fixture();
        $this->mock(AttendanceNotifications::class)->shouldReceive('markChanged')->andThrow(new \RuntimeException('Failed notification'));
        $this->saveMarks($f)->assertStatus(500);
        $f['school']->run(function () {
            $this->assertSame(0, DB::table('asistencia_clases')->count());
            $this->assertSame(0, DB::table('asistencia_marcas')->count());
            $this->assertSame(0, DB::table('user_notification_updates')->count());
        });
        $this->app->forgetInstance(AttendanceNotifications::class);
        $this->saveMarks($f, 'presente')->assertOk()->assertHeader('X-Notification-Record');
        $this->news($f, 'estudiante', 0);
        $this->news($f, 'peer', 0);
    }

    public function test_attendance_only_plan_and_read_only_student_permissions_work_without_aula(): void
    {
        $f = $this->fixture();
        Plan::where('key', 'premium')->update(['features' => ['nucleo', 'academico', 'asistencia']]);
        $this->saveMarks($f)->assertOk();
        $f['school']->run(fn () => Role::findByName('estudiante', 'web')->syncPermissions(['asistencia.consultar_propia']));
        $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.event', 'attendance.absent');
        $this->getJson($f['base'].'/asistencias/mis-faltas')->assertOk()->assertJsonCount(1, 'data.faltas');
        $this->postJson($f['base'].'/asistencias/solicitudes', ['matricula_token' => $f['enrollmentToken'],
            'motivo' => 'Motivo privado de prueba', 'marcas' => [['sesion_token' => $f['session'], 'fecha' => '2028-02-01']]])->assertForbidden();
        Plan::where('key', 'premium')->update(['features' => ['nucleo', 'academico']]);
        $this->getJson($f['base'].'/notification-updates')->assertForbidden();
    }

    public function test_aula_and_attendance_share_the_feed_and_keep_recipients_separate(): void
    {
        $f = $this->fixture();
        $this->saveMarks($f)->assertOk();
        $f['school']->run(function () {
            $assignment = AsignacionDocente::firstOrFail();
            $aula = Aula::create(['ano_lectivo_id' => $assignment->ano_lectivo_id,
                'grupo_id' => $assignment->grupo_id, 'materia_id' => $assignment->materia_id]);
            $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => Periodo::firstOrFail()->id,
                'titulo' => 'Sección visible', 'visible_estudiantes' => true, 'autor_id' => User::where('role', 'rector')->value('id')]);
            $resource = AulaRecurso::create(['seccion_id' => $section->id, 'tipo' => 'texto', 'titulo' => 'Recurso publicado',
                'visible_estudiantes' => true, 'estado' => 'publicado', 'autor_id' => $section->autor_id, 'version' => 1]);
            app(AulaNotifications::class)->resourceChanged($resource);
        });
        $feed = $this->news($f, 'estudiante', 2);
        $this->assertEqualsCanonicalizing(['attendance.absent', 'aula.resource.published'], array_column($feed->json('data'), 'event'));
        $this->news($f, 'peer', 1)->assertJsonPath('data.0.event', 'aula.resource.published');
        $this->news($f, 'rector', 1)->assertJsonPath('data.0.event', 'aula.resource.published');
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
        Carbon::setTestNow('2028-02-01 15:00:00 UTC');
        CarbonImmutable::setTestNow('2028-02-01 15:00:00 UTC');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
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
}
