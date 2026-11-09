<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
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
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventNotifications;
use App\Services\SieeConfiguration;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\DatabaseConfig;
use Tests\TestCase;

class EventNotificationTest extends TestCase
{
    private ?Tenant $gradebookSchool = null;

    private string $gradebookActor = 'rector';

    private function input(array $f, array $changes = []): array
    {
        return [...['titulo' => 'Feria del grupo', 'descripcion' => 'Proyectos de ciencias', 'fecha' => '2028-02-15',
            'hora_inicio' => '07:00', 'hora_fin' => '08:00', 'categoria' => 'actividad', 'institucional' => false,
            'grupo_tokens' => [$f['groupToken']], 'materia_token' => $f['subjectToken'], 'enviar_correo' => false], ...$changes];
    }

    private function createEvent(array $f, string $role = 'docente', array $changes = []): array
    {
        $this->gradebookAuthenticate($f, $role);

        return $this->postJson($f['base'].'/eventos?opaque=1', $this->input($f, $changes))
            ->assertCreated()->assertHeader('X-Notification-Record')->json('data');
    }

    private function news(array $f, string $role, int $count): TestResponse
    {
        $this->gradebookAuthenticate($f, $role);

        return $this->getJson($f['base'].'/notification-updates')->assertOk()->assertJsonPath('meta.total', $count);
    }

    private function publish(array $f, array $event): void
    {
        $this->gradebookAuthenticate($f, 'docente');
        $this->postJson($f['base'].'/eventos/'.$event['url_token'].'/publicar?opaque=1', ['version' => $event['version']])
            ->assertOk()->assertHeader('X-Notification-Record');
    }

    public function test_drafts_records_publication_retries_edits_and_deletion_are_consistent(): void
    {
        $f = $this->gradebookFixture();
        $event = $this->createEvent($f);
        $this->news($f, 'estudiante', 0);
        $this->publish($f, $event);
        foreach (['estudiante', 'docente', 'rector'] as $role) {
            $feed = $this->news($f, $role, 1)->assertJsonPath('data.0.event', 'calendar.event.published')
                ->assertJsonPath('data.0.url', '/comunicacion/eventos?evento='.$event['url_token'])
                ->assertJsonPath('data.0.event_time', '07:00–08:00')->assertJsonPath('data.0.group', 'Primero / 01A');
            $this->assertDoesNotMatchRegularExpression('/"(?:id|user_id|evento_id|created_by)"\s*:/', $feed->getContent());
        }
        // An already-published retry and an unchanged save do not send the news twice.
        $this->publish($f, $event);
        $this->putJson($f['base'].'/eventos/'.$event['url_token'].'?opaque=1', $this->input($f, ['version' => 2]))->assertOk();
        $this->news($f, 'estudiante', 1);
        $this->gradebookAuthenticate($f, 'docente');
        $url = $f['base'].'/eventos/'.$event['url_token'].'?opaque=1';
        $this->putJson($url, $this->input($f, ['version' => 3, 'titulo' => 'Feria actualizada']))->assertOk()->assertHeader('X-Notification-Record');
        $this->putJson($url, $this->input($f, ['version' => 3, 'titulo' => 'Conflicto']))->assertConflict()->assertHeader('X-Notification-Record');
        $this->news($f, 'estudiante', 2);
        $this->gradebookAuthenticate($f, 'docente');
        $this->deleteJson($url)->assertOk()->assertHeader('X-Notification-Record');
        $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.event', 'calendar.event.deleted');
        $this->getJson($url)->assertOk()->assertJsonPath('data.eliminado', true)->assertJsonPath('data.puede_editar', false)->assertJsonPath('data.archivos', []);
        $this->deleteJson($url)->assertNotFound();
        $this->gradebookAuthenticate($f, 'docente');
        $logs = $this->getJson($f['base'].'/notification-records')->assertOk()->json('data');
        $this->assertContains('calendar.event.store', array_column($logs, 'event'));
        $this->assertContains('calendar.event.publish', array_column($logs, 'event'));
        $this->assertContains('calendar.event.update', array_column($logs, 'event'));
        $this->assertContains('calendar.event.destroy', array_column($logs, 'event'));
        $this->assertContains('danger', array_column($logs, 'variant'));
    }

    public function test_recipients_current_scope_institutional_and_tenant_isolation(): void
    {
        $f = $this->gradebookFixture();
        $extra = $f['school']->run(function () {
            $tokens = [];
            foreach (['estudiante', 'docente'] as $role) {
                $user = User::create(['name' => 'Sin asignación', 'email' => $role.'@unrelated.test', 'password' => bcrypt('Temporary1!abc'),
                    'role' => $role, 'status' => 'active', 'must_change_password' => false]);
                $user->assignRole($role);
                $tokens['other-'.$role] = $user->createToken('test')->plainTextToken;
            }

            return $tokens;
        });
        $f['bearers'] = [...$f['bearers'], ...$extra];
        $event = $this->createEvent($f);
        $this->publish($f, $event);
        $this->news($f, 'other-estudiante', 0);
        $this->news($f, 'other-docente', 0);
        $this->getJson($f['base'].'/eventos/'.$event['url_token'].'?opaque=1')->assertNotFound();
        $this->postJson($f['base'].'/eventos?opaque=1', $this->input($f))->assertForbidden()->assertHeader('X-Notification-Record');
        $f['school']->run(fn () => Matricula::query()->update(['estado' => 'retirada']));
        $this->news($f, 'estudiante', 0);
        $institutional = $this->createEvent($f, 'rector', ['institucional' => true, 'grupo_tokens' => [], 'materia_token' => null]);
        $this->postJson($f['base'].'/eventos/'.$institutional['url_token'].'/publicar?opaque=1', ['version' => 1])->assertOk();
        $this->news($f, 'other-estudiante', 1)->assertJsonPath('data.0.institutional', true);
        $this->news($f, 'other-docente', 1);
        $this->gradebookAuthenticate($f, 'rector');
        $this->getJson($f['base'].'/eventos/1?opaque=1')->assertNotFound();
        $g = $this->gradebookFixture();
        $this->getJson($g['base'].'/eventos/'.$event['url_token'].'?opaque=1')->assertNotFound();
        $this->gradebookAuthenticate($g, 'rector');
        $this->getJson($f['base'].'/notification-updates')->assertUnauthorized();
    }

    public function test_notification_failure_rolls_back_publication_and_draft_delete_sends_no_news(): void
    {
        $f = $this->gradebookFixture();
        $event = $this->createEvent($f);
        $this->mock(EventNotifications::class, function ($mock) {
            $mock->shouldReceive('changed')->once()->andThrow(new \RuntimeException('Notification failure'));
        });
        $this->postJson($f['base'].'/eventos/'.$event['url_token'].'/publicar?opaque=1', ['version' => 1])->assertStatus(500);
        $f['school']->run(function () {
            $this->assertSame('borrador', Evento::firstOrFail()->estado);
            $this->assertSame(0, DB::table('user_notification_updates')->count());
        });
        $this->app->forgetInstance(EventNotifications::class);
        $this->deleteJson($f['base'].'/eventos/'.$event['url_token'].'?opaque=1')->assertOk();
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'docente');
        $this->getJson($f['base'].'/eventos/'.$event['url_token'].'?opaque=1')->assertNotFound();
    }

    public function test_all_assigned_groups_receive_news_without_aula_and_revoked_access_is_rechecked(): void
    {
        $f = $this->gradebookFixture();
        Plan::where('key', 'premium')->update(['features' => ['nucleo', 'academico', 'comunicacion_basica']]);
        $extra = $f['school']->run(function () {
            $group = Grupo::firstOrFail()->replicate();
            $group->nombre = '01B';
            $group->save();
            $student = User::create(['name' => 'Estudiante del segundo grupo', 'email' => 'second@events.test',
                'password' => bcrypt('Temporary1!abc'), 'role' => 'estudiante', 'status' => 'active', 'must_change_password' => false]);
            $student->assignRole('estudiante');
            Matricula::create(['ano_lectivo_id' => $group->ano_lectivo_id, 'grupo_id' => $group->id,
                'estudiante_id' => $student->id, 'estado' => 'activa']);

            return ['group' => OpaqueUrlToken::for('grupo', $group->id), 'bearer' => $student->createToken('test')->plainTextToken];
        });
        $f['bearers']['second'] = $extra['bearer'];
        $event = $this->createEvent($f, 'rector', ['grupo_tokens' => [$f['groupToken'], $extra['group']]]);
        $this->postJson($f['base'].'/eventos/'.$event['url_token'].'/publicar?opaque=1', ['version' => 1])->assertOk();
        $this->news($f, 'estudiante', 1);
        $this->news($f, 'second', 1)->assertJsonPath('data.0.group', 'Primero / 01A, Primero / 01B');
        $this->news($f, 'docente', 1);
        $f['school']->run(fn () => User::where('email', 'second@events.test')->firstOrFail()->syncRoles([]));
        $this->gradebookAuthenticate($f, 'second');
        $this->getJson($f['base'].'/notification-updates')->assertForbidden();
        Plan::where('key', 'premium')->update(['features' => ['nucleo', 'academico']]);
        $this->gradebookAuthenticate($f, 'rector');
        $this->getJson($f['base'].'/notification-updates')->assertForbidden();
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
