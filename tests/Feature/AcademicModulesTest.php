<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Controllers\Api\Academico\EventoController;
use App\Http\Controllers\Api\Academico\HorarioController;
use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\EscalaValorativaController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Api\Academico\SieeController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Evento;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\Academico\Sede;
use App\Models\Academico\SesionHorario;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionMatrix;
use App\Services\EventAccess;
use App\Services\GradebookService;
use App\Services\GradeCalculationService;
use App\Services\AcademicRecoveryService;
use App\Services\AcademicPromotionService;
use App\Services\DuplicarAnoLectivoService;
use App\Jobs\VerifyTenantMigrations;
use App\Services\HorarioService;
use App\Services\PeriodoLifecycleService;
use App\Services\SieeConfiguration;
use App\Support\Sedes\SedeLimits;
use App\Support\OpaqueUrlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AcademicModulesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\FlexibleGradingTests;
    use \Tests\Support\AcademicWorkflowTests;

    public function test_visual_assessment_maps_exact_fraction_and_hides_numeric_result(): void
    {
        $choices = new \Illuminate\Database\Eloquent\Collection([
            new \App\Models\Academico\EscalaOpcion(['escala_id' => 1, 'nombre' => 'Bien',
                'valor_equivalente' => '4.00', 'orden' => 1, 'aprueba' => true]),
            new \App\Models\Academico\EscalaOpcion(['escala_id' => 1, 'nombre' => 'En proceso',
                'valor_equivalente' => '2.50', 'orden' => 2, 'aprueba' => false]),
        ]);
        foreach ($choices as $index => $choice) {
            $choice->id = $index + 1;
        }
        $raw = ['estado' => 'calculado', 'exact_value' => '13/4', 'raw_value' => '3.25',
            'display_value' => '3.2', 'aprobado' => true, 'trace' => ['value' => '13/4']];
        $decorated = app(\App\Services\EscalaVisualService::class)->decorate($raw, $choices);
        $public = \App\Support\EvaluationOpaquePresenter::result($decorated, true);

        $this->assertSame('En proceso', $public['valoracion']['nombre']);
        $this->assertFalse($public['aprobado']);
        foreach (['exact_value', 'raw_value', 'display_value', 'trace'] as $field) {
            $this->assertArrayNotHasKey($field, $public);
        }
    }

    public function test_visual_categories_can_be_configured_and_given_a_private_image(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $numeric = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Numérica', 'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS,
            'escala_id' => $numeric->id, 'metodo_id' => $method->id]]);
        $visual = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nivel_educativo' => 'preescolar', 'nombre' => 'Caritas', 'tipo' => 'imagenes']);
        $request = Request::create('/', 'PUT', ['opciones' => [
            ['nombre' => 'Muy bien', 'valor_equivalente' => '5', 'emoji' => '😄', 'aprueba' => true],
            ['nombre' => 'Necesita apoyo', 'valor_equivalente' => '2', 'emoji' => '😟', 'aprueba' => false],
        ]]);
        $request->setUserResolver(fn () => $this->rector);
        $options = app(\App\Http\Controllers\Api\Academico\EscalaOpcionController::class)
            ->save($request, $visual->id)->getData(true)['data'];
        $this->assertCount(2, $options);
        $this->assertSame(['Muy bien', 'Necesita apoyo'], array_column($options, 'nombre'));
        $this->assertSame('5.00', $visual->opciones()->orderBy('orden')->first()->valor_equivalente);

        Storage::fake('tenant');
        $file = UploadedFile::fake()->image('carita.png', 128, 128);
        $upload = Request::create('/', 'POST', [], [], ['imagen' => $file]);
        $upload->setUserResolver(fn () => $this->rector);
        $saved = app(\App\Http\Controllers\Api\Academico\EscalaOpcionController::class)
            ->upload($upload, $visual->id, $visual->opciones()->orderBy('orden')->first()->id)
            ->getData(true)['data'];
        $this->assertNotNull($saved['imagen_url']);
        $this->assertTrue(Storage::disk('tenant')->exists($visual->opciones()->orderBy('orden')->first()->fresh()->imagen_path));
    }

    public function test_preschool_grade_saves_choice_and_numeric_equivalent_but_reports_face(): void
    {
        $numeric = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Numérica', 'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'modo_asignatura' => 'SIMPLE_AVERAGE',
            'escala_id' => $numeric->id, 'metodo_id' => $method->id]]);
        $visual = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nivel_educativo' => 'preescolar', 'nombre' => 'Caritas', 'tipo' => 'imagenes']);
        $choice = \App\Models\Academico\EscalaOpcion::create(['escala_id' => $visual->id,
            'nombre' => 'Bien', 'orden' => 1, 'valor_equivalente' => '4.00', 'emoji' => '🙂', 'aprueba' => true]);
        $needsSupport = \App\Models\Academico\EscalaOpcion::create(['escala_id' => $visual->id,
            'nombre' => 'En proceso', 'orden' => 2, 'valor_equivalente' => '3.20', 'emoji' => '😐', 'aprueba' => false]);
        $level = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Preescolar',
            'nivel_educativo' => 'preescolar', 'estado' => 'activo']);
        $grade = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $level->id,
            'nombre' => 'Transición', 'codigo' => 'TR', 'estado' => 'activo']);
        $group = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $grade->id,
            'nombre' => 'A', 'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'estado' => 'activo']);
        $assignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id,
            'grupo_id' => $group->id, 'materia_id' => $this->subject->id, 'docente_id' => $this->teacher->id]);
        $student = User::create(['name' => 'Estudiante', 'email' => 'preescolar@example.invalid',
            'password' => 'ExamplePassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31', 'estado' => 'abierto']);
        $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id,
            'periodo_id' => $period->id, 'nombre' => 'Actividades', 'modo' => 'SIMPLE_AVERAGE']);
        $activity = ActividadEvaluacion::create(['componente_id' => $component->id,
            'nombre' => 'Exploración', 'fecha' => '2026-02-01']);
        app(GradebookService::class)->saveGrades($this->teacher, $assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => null, 'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $choice->id),
            'version' => 0,
        ]]);
        $saved = Calificacion::where('actividad_id', $activity->id)->where('matricula_id', $enrollment->id)->firstOrFail();
        $this->assertSame($choice->id, $saved->escala_opcion_id);
        $this->assertSame('4', (string) $saved->valor);
        $public = \App\Support\EvaluationOpaquePresenter::report(app(GradebookService::class)->report($enrollment));
        $this->assertSame('Bien', $public['asignaturas'][0]['periodos'][0]['valoracion']['nombre']);
        $this->assertArrayNotHasKey('display_value', $public['asignaturas'][0]['periodos'][0]);
        $this->assertSame([], app(GradebookService::class)->failedEnrollmentIds(collect([$enrollment])));
        app(GradebookService::class)->saveGrades($this->teacher, $assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => null, 'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $needsSupport->id),
            'version' => 1,
        ]]);
        $this->assertTrue(app(GradebookService::class)->report($enrollment)['asignaturas'][0]['periodos'][0]['aprobado'] === false);
        $this->assertSame([$enrollment->id], app(GradebookService::class)->failedEnrollmentIds(collect([$enrollment])));
    }

    public function test_period_dates_open_the_current_period_and_close_elapsed_periods(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00', 'America/Bogota'));

        try {
            foreach ([
                [1, 'P1', '2026-01-01', '2026-03-31'],
                [2, 'P2', '2026-04-01', '2026-06-30'],
                [3, 'P3', '2026-07-01', '2026-12-31'],
            ] as [$order, $name, $start, $end]) {
                Periodo::create([
                    'ano_lectivo_id' => $this->year->id, 'orden' => $order,
                    'nombre' => $name, 'fecha_inicio' => $start, 'fecha_fin' => $end,
                    'estado' => Periodo::ESTADO_PLANIFICADO,
                ]);
            }

            $controller = app(PeriodoController::class);
            $atBoundary = $controller->index((string) $this->year->id)->getData(true)['data'];
            $this->assertSame(['cerrado', 'abierto', 'planificado'], array_column($atBoundary, 'estado'));
            $this->assertSame([false, true, false], array_column($atBoundary, 'es_actual'));
            $yearDetail = app(AnoLectivoController::class)->show((string) $this->year->id)->getData(true)['data'];
            $this->assertSame([false, true, false], array_column($yearDetail['periodos'], 'es_actual'));
            $this->assertDatabaseHas('audit_logs', [
                'recurso' => 'periodo', 'recurso_id' => (string) $atBoundary[0]['id'], 'accion' => 'UPDATE',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'America/Bogota'));
            $nextDay = $controller->index((string) $this->year->id)->getData(true)['data'];
            $this->assertSame(['cerrado', 'cerrado', 'abierto'], array_column($nextDay, 'estado'));
            $this->assertSame([false, false, true], array_column($nextDay, 'es_actual'));
            $this->assertSame(0, app(PeriodoLifecycleService::class)->synchronize($this->year));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_reopening_after_the_end_date_survives_automatic_synchronization(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'America/Bogota'));

        try {
            $period = Periodo::create([
                'ano_lectivo_id' => $this->year->id, 'orden' => 1, 'nombre' => 'P1',
                'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31',
                'estado' => Periodo::ESTADO_CERRADO,
            ]);
            $request = Request::create('/api/periodos/'.$period->id.'/reabrir', 'POST');
            $request->setUserResolver(fn () => $this->rector);
            $controller = app(PeriodoController::class);

            $reopened = $controller->reabrir($request, (string) $period->id)->getData(true)['data'];
            $this->assertSame(Periodo::ESTADO_ABIERTO, $reopened['estado']);
            $this->assertTrue($reopened['reapertura_manual']);
            $this->assertFalse($reopened['es_actual']);
            $this->assertSame(0, app(PeriodoLifecycleService::class)->synchronize($this->year));
            $this->assertSame(Periodo::ESTADO_ABIERTO, $period->fresh()->estado);
            $this->assertTrue($period->fresh()->reapertura_manual);

            $closed = $controller->cerrar($request, (string) $period->id)->getData(true)['data'];
            $this->assertSame(Periodo::ESTADO_CERRADO, $closed['estado']);
            $this->assertFalse($closed['reapertura_manual']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_starting_a_year_immediately_synchronizes_its_periods(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'America/Bogota'));

        try {
            $this->year->update(['estado' => AnoLectivo::ESTADO_PLANIFICADO]);
            foreach ([
                [1, '2026-01-01', '2026-03-31'],
                [2, '2026-04-01', '2026-08-31'],
                [3, '2026-09-01', '2026-12-31'],
            ] as [$order, $start, $end]) {
                Periodo::create([
                    'ano_lectivo_id' => $this->year->id, 'orden' => $order,
                    'nombre' => 'P'.$order, 'fecha_inicio' => $start,
                    'fecha_fin' => $end, 'estado' => Periodo::ESTADO_PLANIFICADO,
                ]);
            }
            $request = Request::create('/api/anos-lectivos/'.$this->year->id.'/iniciar', 'POST');
            $request->setUserResolver(fn () => $this->rector);

            app(AnoLectivoController::class)->iniciar($request, (string) $this->year->id);

            $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);
            $this->assertSame(['cerrado', 'abierto', 'planificado'],
                Periodo::where('ano_lectivo_id', $this->year->id)->orderBy('orden')->pluck('estado')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_coordinator_can_manage_academic_years_but_cannot_transition_years_or_periods(): void
    {
        foreach (['academico.anos.transicionar', 'academico.periodos.transicionar'] as $key) {
            $this->assertContains($key, PermissionMatrix::defaultGrantsFor('rector'));
            $this->assertNotContains($key, PermissionMatrix::defaultGrantsFor('coord_academico'));
            $this->assertNotContains($key, PermissionMatrix::defaultGrantsFor('coord_combinado'));
        }
        $this->assertContains('academico.anos.gestionar', PermissionMatrix::defaultGrantsFor('coord_academico'));

        Role::findOrCreate('coord_academico', 'web');
        $coordinator = User::create([
            'name' => 'Coordinadora', 'email' => 'coordinator@test.test',
            'password' => 'CoordinatorPassword123', 'role' => 'coord_academico', 'status' => 'active',
        ]);
        $coordinator->assignRole('coord_academico');
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $coordinator);
        $period = Periodo::create([
            'ano_lectivo_id' => $this->year->id, 'orden' => 1, 'nombre' => 'P1',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Periodo::ESTADO_ABIERTO,
        ]);
        $periods = app(PeriodoController::class);
        $years = app(AnoLectivoController::class);

        foreach (['abrir', 'cerrar', 'reabrir'] as $action) {
            $this->assertForbiddenAcademicTransition(fn () => $periods->{$action}($request, (string) $period->id));
        }
        foreach (['iniciar', 'cerrar'] as $action) {
            $this->assertForbiddenAcademicTransition(fn () => $years->{$action}($request, (string) $this->year->id));
        }
        $this->assertSame(Periodo::ESTADO_ABIERTO, $period->fresh()->estado);
        $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);

        $request->setUserResolver(fn () => $this->rector);
        $this->year->update(['num_periodos' => 1]);
        $this->assertSame(Periodo::ESTADO_CERRADO,
            $periods->cerrar($request, (string) $period->id)->getData(true)['data']['estado']);
        $request->merge(['confirmar_sin_matriculas' => true]);
        $this->assertSame(AnoLectivo::ESTADO_CERRADO,
            $years->cerrar($request, (string) $this->year->id)->getData(true)['data']['estado']);
    }

    public function test_year_close_requires_complete_periods_and_explicit_empty_year_confirmation(): void
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->rector);
        $controller = app(AnoLectivoController::class);
        $review = $controller->revisionCierre($request, (string) $this->year->id,
            app(\App\Services\AcademicYearReviewService::class))->getData(true)['data'];
        $this->assertContains('period_count', $review['bloqueos']);
        $this->assertFalse($review['puede_cerrar']);

        $this->year->update(['num_periodos' => 1]);
        Periodo::create(['ano_lectivo_id' => $this->year->id, 'orden' => 1,
            'nombre' => 'Único período', 'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31', 'estado' => Periodo::ESTADO_CERRADO]);
        $ready = $controller->revisionCierre($request, (string) $this->year->id,
            app(\App\Services\AcademicYearReviewService::class))->getData(true)['data'];
        $this->assertTrue($ready['sin_matriculas']);
        $this->assertTrue($ready['puede_cerrar']);
        try {
            $controller->cerrar($request, (string) $this->year->id,
                app(\App\Services\AcademicYearReviewService::class));
            $this->fail('El cierre vacío exige confirmación explícita.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmar_sin_matriculas', $exception->errors());
        }
        $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);

        $request->merge(['confirmar_sin_matriculas' => true]);
        $controller->cerrar($request, (string) $this->year->id,
            app(\App\Services\AcademicYearReviewService::class));
        $this->assertSame(AnoLectivo::ESTADO_CERRADO, $this->year->fresh()->estado);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'ano_lectivo',
            'recurso_id' => (string) $this->year->id, 'accion' => 'UPDATE']);
    }

    public function test_year_with_only_withdrawn_enrollment_requires_explicit_confirmation(): void
    {
        $this->year->update(['num_periodos' => 1]);
        Periodo::create(['ano_lectivo_id' => $this->year->id, 'orden' => 1,
            'nombre' => 'Único período', 'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31', 'estado' => Periodo::ESTADO_CERRADO]);
        $student = User::create(['name' => 'Estudiante de prueba', 'email' => 'student-close@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'retirada']);
        $request = Request::create('/', 'POST', ['confirmar_sin_matriculas' => true]);
        $request->setUserResolver(fn () => $this->rector);
        $controller = app(AnoLectivoController::class);
        $review = $controller->revisionCierre($request, (string) $this->year->id,
            app(\App\Services\AcademicYearReviewService::class))->getData(true)['data'];
        $this->assertSame(1, $review['matriculas']);
        $this->assertTrue($review['solo_retiradas']);
        $this->assertTrue($review['puede_cerrar']);
        try {
            $controller->cerrar($request, (string) $this->year->id,
                app(\App\Services\AcademicYearReviewService::class));
            $this->fail('Un año con solo matrículas retiradas exige confirmación diferenciada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmar_solo_retiradas', $exception->errors());
        }
        $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);
        $request->merge(['confirmar_solo_retiradas' => true]);
        $controller->cerrar($request, (string) $this->year->id);
        $this->assertSame(AnoLectivo::ESTADO_CERRADO, $this->year->fresh()->estado);
    }

    public function test_year_review_detects_calendar_gap_and_rejects_shrinking_dates_around_periods(): void
    {
        foreach ([
            [1, '2026-01-01', '2026-03-31'],
            [2, '2026-04-02', '2026-08-31'],
            [3, '2026-09-01', '2026-12-31'],
        ] as [$order, $start, $end]) {
            Periodo::create(['ano_lectivo_id' => $this->year->id, 'orden' => $order,
                'nombre' => 'P'.$order, 'fecha_inicio' => $start, 'fecha_fin' => $end,
                'estado' => Periodo::ESTADO_CERRADO]);
        }
        $review = app(\App\Services\AcademicYearReviewService::class)->review($this->year);
        $this->assertContains('period_gap_or_overlap', $review['bloqueos']);
        $this->assertFalse($review['puede_cerrar']);

        $request = Request::create('/', 'PUT', ['nombre' => '2026', 'tipo_calendario' => 'A',
            'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-12-31', 'num_periodos' => 3]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(AnoLectivoController::class)->update($request, (string) $this->year->id);
            $this->fail('Editar el año no debe dejar períodos existentes fuera del calendario.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('2026-01-01', $this->year->fresh()->fecha_inicio->toDateString());
    }

    public function test_year_close_review_http_uses_public_selector_and_rector_permission(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $token = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $this->getJson("http://localhost/api/anos-lectivos/{$token}/revision-cierre?opaque=1")
            ->assertOk()->assertJsonPath('data.ano_token', $token)
            ->assertJsonMissingPath('data.ano_lectivo_id');
    }

    public function test_curriculum_evaluation_can_be_prepared_without_teacher_or_students_and_applied_once(): void
    {
        $this->assignment->update(['docente_id' => null]);
        $curriculumId = DB::table('materias_curriculares')->where('ano_lectivo_id', $this->year->id)
            ->where('grado_id', $this->group->grado_id)->where('materia_id', $this->subject->id)->value('id');
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'orden' => 1,
            'nombre' => 'P1', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30',
            'estado' => Periodo::ESTADO_PLANIFICADO]);
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Numérica', 'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS,
            'escala_id' => $scale->id, 'metodo_id' => $method->id]]);

        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $selection = [
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
        ];
        $url = "http://localhost/api/siee/{$yearToken}/preparacion";
        $this->getJson($url.'?'.http_build_query($selection))
            ->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.completa', false);
        $components = [['nombre' => 'Trabajos', 'modo' => 'SIMPLE_AVERAGE', 'peso' => '100',
            'actividades' => [['nombre' => 'Proyecto', 'fecha' => '2026-03-15', 'peso' => null]]]];
        $this->putJson($url, [...$selection, 'version' => 0, 'componentes' => $components])
            ->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.completa', true)
            ->assertJsonPath('data.componentes.0.peso', '100')
            ->assertJsonMissingPath('data.materia_curricular_id');
        $this->assertSame(0, Matricula::count());
        $this->assertSame(0, Calificacion::count());
        $this->putJson($url, [...$selection, 'version' => 0, 'componentes' => $components])
            ->assertStatus(409);

        $duplicate = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['curriculo' => true, 'periodos' => true], $this->rector);
        $targetCurriculumIds = DB::table('materias_curriculares')->where('ano_lectivo_id', $duplicate->id)->pluck('id');
        $this->assertSame(1, DB::table('preparaciones_evaluacion')
            ->whereIn('materia_curricular_id', $targetCurriculumIds)->count());
        $this->assertDatabaseHas('actividades_preparadas', ['nombre' => 'Proyecto', 'fecha' => '2027-03-15']);
        $this->assertSame(0, AsignacionDocente::where('ano_lectivo_id', $duplicate->id)->count());

        $lateCopy = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2028', 'tipo_calendario' => 'A', 'fecha_inicio' => '2028-01-01',
            'fecha_fin' => '2028-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['curriculo' => true], $this->rector);
        $lateCurriculumIds = DB::table('materias_curriculares')->where('ano_lectivo_id', $lateCopy->id)->pluck('id');
        $this->assertSame(0, DB::table('preparaciones_evaluacion')
            ->whereIn('materia_curricular_id', $lateCurriculumIds)->count());
        app(DuplicarAnoLectivoService::class)->copiarConfiguracion($this->year, $lateCopy,
            ['periodos' => true], $this->rector);
        $this->assertSame(1, DB::table('preparaciones_evaluacion')
            ->whereIn('materia_curricular_id', $lateCurriculumIds)->count());

        $apply = $url.'/aplicar';
        $body = ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $this->assignment->id),
            'periodo_token' => $selection['periodo_token']];
        $foreignPeriod = Periodo::where('ano_lectivo_id', $duplicate->id)->firstOrFail();
        $this->postJson($apply, [...$body,
            'periodo_token' => OpaqueUrlToken::for('periodo', $foreignPeriod->id)])->assertNotFound();
        $otherGroup = Grupo::create(['grado_id' => $this->group->grado_id,
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'B',
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'estado' => 'activo']);
        $otherAssignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id,
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id, 'docente_id' => null]);
        ComponenteEvaluacion::create(['asignacion_id' => $otherAssignment->id,
            'periodo_id' => $period->id, 'nombre' => 'Manual', 'modo' => 'SIMPLE_AVERAGE']);
        $this->postJson($apply, ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $otherAssignment->id),
            'periodo_token' => $selection['periodo_token']])->assertUnprocessable();
        $this->postJson($apply, $body)->assertOk()->assertJsonPath('data.creados', 1);
        $this->postJson($apply, $body)->assertOk()->assertJsonPath('data.creados', 0)
            ->assertJsonPath('data.ya_existentes', 1);
        $this->assertSame(2, ComponenteEvaluacion::count());
        $this->assertSame(1, ActividadEvaluacion::count());
        $this->assertNotNull(ComponenteEvaluacion::where('asignacion_id', $this->assignment->id)
            ->firstOrFail()->componente_preparado_id);
        $this->assertNotNull(DB::table('preparaciones_evaluacion')->value('applied_at'));
        $this->putJson($url, [...$selection, 'version' => 1, 'componentes' => $components])
            ->assertUnprocessable();
        $appliedComponent = ComponenteEvaluacion::where('asignacion_id', $this->assignment->id)->firstOrFail();
        ActividadEvaluacion::where('componente_id', $appliedComponent->id)->delete();
        $appliedComponent->delete();
        $this->putJson($url, [...$selection, 'version' => 1, 'componentes' => $components])
            ->assertUnprocessable();
        $this->assertSame($curriculumId, DB::table('preparaciones_evaluacion')->value('materia_curricular_id'));
    }

    public function test_rector_role_without_transition_permissions_cannot_change_states(): void
    {
        $rectorRole = Role::findByName('rector', 'web');
        foreach (['academico.anos.transicionar', 'academico.periodos.transicionar'] as $permission) {
            $rectorRole->revokePermissionTo($permission);
            $this->assertFalse($this->rector->can($permission));
        }
        $period = Periodo::create([
            'ano_lectivo_id' => $this->year->id, 'orden' => 1, 'nombre' => 'P1',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Periodo::ESTADO_ABIERTO,
        ]);
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->rector);

        $this->assertForbiddenAcademicTransition(
            fn () => app(AnoLectivoController::class)->cerrar($request, (string) $this->year->id));
        $this->assertForbiddenAcademicTransition(
            fn () => app(PeriodoController::class)->cerrar($request, (string) $period->id));
        $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);
        $this->assertSame(Periodo::ESTADO_ABIERTO, $period->fresh()->estado);
    }

    private function assertForbiddenAcademicTransition(callable $transition): void
    {
        try {
            $transition();
            $this->fail('Un usuario sin permiso no debe poder cambiar el estado lectivo.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_siee_configuration_does_not_expose_or_store_a_separate_version(): void
    {
        $this->assertFalse(Schema::hasColumn('anos_lectivos', 'siee_version'));

        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica',
            'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $configuration = [...SieeConfiguration::DEFAULTS, 'escala_token' => OpaqueUrlToken::for('escala-valorativa', $scale->id),
            'metodo_token' => OpaqueUrlToken::for('metodo-aprobacion', $method->id)];
        unset($configuration['escala_id'], $configuration['metodo_id']);
        $request = Request::create('/', 'PUT', $configuration);
        $request->setUserResolver(fn () => $this->rector);

        $controller = app(SieeController::class);
        $response = $controller->update($request, $this->year->id)->getData(true)['data'];
        $resolved = app(SieeConfiguration::class)->resolve($this->year->fresh());
        $result = (new GradebookService)->calculate(['mode' => 'SIMPLE_AVERAGE',
            'inputs' => [['value' => '4']]], $resolved);

        $this->assertArrayNotHasKey('version', $response);
        $this->assertArrayNotHasKey('version', $resolved);
        $this->assertArrayNotHasKey('siee_version', $result);
        $this->assertArrayHasKey('calculation_version', $result);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'UPDATE', 'recurso' => 'siee',
            'recurso_id' => (string) $this->year->id]);
        foreach (['modo_area', 'modo_asignatura', 'modo_anual'] as $field) {
            $manualRequest = Request::create('/', 'PUT', [...$configuration, $field => 'MANUAL']);
            $manualRequest->setUserResolver(fn () => $this->rector);
            try {
                $controller->update($manualRequest, $this->year->id);
                $this->fail('El modo manual no puede activarse sin persistencia y captura autorizada.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }

    public function test_historical_siee_uses_one_decimal_half_down_without_changing_original_configuration(): void
    {
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica',
            'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 2]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS,
            'redondeo' => 'HALF_UP', 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);

        $resolved = app(SieeConfiguration::class)->resolve($this->year->fresh());
        $this->assertSame(1, $resolved['decimales']);
        $this->assertSame('HALF_DOWN', $resolved['redondeo']);
        $this->assertSame('3.1', (new GradeCalculationService)->result([
            'mode' => 'WEIGHTED_AVERAGE', 'inputs' => [
                ['value' => '1.5', 'weight' => '30'],
                ['value' => '3', 'weight' => '40'],
                ['value' => '5', 'weight' => '30'],
            ],
        ], $resolved)['display_value']);
        $this->assertSame(2, $scale->fresh()->decimales);
    }

    public function test_new_numeric_scales_reject_other_decimal_precisions(): void
    {
        $request = Request::create('/', 'POST', [
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica',
            'valor_min' => '0', 'valor_max' => '5', 'decimales' => 2,
        ]);

        $this->expectException(ValidationException::class);
        app(EscalaValorativaController::class)->store($request);
    }

    public function test_year_url_selector_is_stable_opaque_and_tenant_bound(): void
    {
        $controller = app(AnoLectivoController::class);
        $first = $controller->index()->getData(true)['data'][0];
        $again = $controller->index()->getData(true)['data'][0];
        $this->assertSame($first['url_token'], $again['url_token']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $first['url_token']);
        $this->assertNotSame((string) $this->year->id, $first['url_token']);
        $this->assertSame(OpaqueUrlToken::for('ano-lectivo', $this->year->id), $first['url_token']);
        $this->assertSame(hash_hmac('sha256', $this->school->getTenantKey().'|ano-lectivo|'.$this->year->id, (string) config('app.key')), $first['legacy_url_token']);
        $this->assertNotSame($first['legacy_url_token'], $first['url_token']);

        $otherSchool = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => 'academic-other', 'name' => 'Otro colegio', 'slug' => 'academic-other',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        tenancy()->initialize($otherSchool);
        try {
            $this->assertNotSame($first['url_token'], OpaqueUrlToken::for('ano-lectivo', $this->year->id));
        } finally {
            tenancy()->initialize($this->school);
        }
    }

    public function test_siee_http_routes_require_tenant_bound_year_token_and_configuration_permission(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $api = 'http://localhost/api/siee/';
        $token = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $otherSchool = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => 'academic-other', 'name' => 'Otro colegio', 'slug' => 'academic-other',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        tenancy()->initialize($otherSchool);
        $foreignToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        tenancy()->initialize($this->school);

        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $this->getJson($api.$token)->assertOk();
        $this->getJson($api.$token.'/curriculo')->assertOk();

        foreach ([(string) $this->year->id, $foreignToken, str_repeat('x', 24)] as $invalid) {
            $this->getJson($api.$invalid)->assertNotFound();
            $this->getJson($api.$invalid.'/curriculo')->assertNotFound();
            $this->putJson($api.$invalid, [])->assertNotFound();
            $this->putJson($api.$invalid.'/curriculo', [])->assertNotFound();
        }

        $this->putJson($api.$token, [])->assertUnprocessable()->assertJsonValidationErrors('usar_areas');
        $this->putJson($api.$token.'/curriculo', [])->assertUnprocessable()->assertJsonValidationErrors('grado_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->getJson($api.$token)->assertForbidden();
        $this->putJson($api.$token.'/curriculo', [])->assertForbidden();
    }

    public function test_siee_contract_exposes_and_accepts_only_scoped_opaque_selectors(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $gradeToken = OpaqueUrlToken::for('grado', $this->group->grado_id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        $areaToken = OpaqueUrlToken::for('area', $this->subject->area_id);

        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica',
            'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $configuration = SieeConfiguration::DEFAULTS;
        unset($configuration['escala_id'], $configuration['metodo_id']);
        $configuration['escala_token'] = OpaqueUrlToken::for('escala-valorativa', $scale->id);
        $configuration['metodo_token'] = OpaqueUrlToken::for('metodo-aprobacion', $method->id);

        $this->putJson($api.'/siee/'.$yearToken, $configuration)->assertOk()
            ->assertJsonPath('data.configuracion.escala_token', $configuration['escala_token'])
            ->assertJsonMissingPath('data.configuracion.escala_id');
        $this->putJson($api.'/siee/'.$yearToken.'/curriculo', [
            'grado_token' => $gradeToken, 'materia_token' => $subjectToken,
            'area_token' => $areaToken, 'peso_area' => '20.5',
        ])->assertOk()->assertJsonPath('data.curriculo.0.grado_token', $gradeToken)
            ->assertJsonMissingPath('data.curriculo.0.grado_id');

        $detail = $this->getJson($api.'/siee/'.$yearToken)->assertOk()->json('data');
        $this->assertSame($subjectToken, $detail['materias'][0]['url_token']);
        $this->assertSame($areaToken, $detail['materias'][0]['area']['url_token']);
        $this->assertArrayNotHasKey('id', $detail['grados'][0]);
        $this->assertArrayNotHasKey('id', $detail['escalas'][0]);
        $this->assertArrayNotHasKey('ano_lectivo_id', $detail['metodos'][0]);
        $this->getJson($api.'/siee/'.$yearToken.'/curriculo?grado_token='.$gradeToken)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($api.'/siee/'.$yearToken.'/curriculo?grado_id='.$this->group->grado_id)
            ->assertUnprocessable()->assertJsonValidationErrors('grado_id');
        $this->putJson($api.'/siee/'.$yearToken.'/curriculo', [
            'grado_id' => $this->group->grado_id, 'materia_id' => $this->subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['grado_id', 'materia_id']);
        $this->putJson($api.'/siee/'.$yearToken, [...$configuration, 'escala_id' => $scale->id])
            ->assertUnprocessable()->assertJsonValidationErrors('escala_id');

        $otherYear = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A',
            'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'num_periodos' => 3]);
        $foreignGrade = Grado::create(['ano_lectivo_id' => $otherYear->id,
            'nivel_id' => $this->group->grado->nivel_id, 'nombre' => 'Segundo', 'codigo' => '02', 'estado' => 'activo']);
        $this->putJson($api.'/siee/'.$yearToken.'/curriculo', [
            'grado_token' => OpaqueUrlToken::for('grado', $foreignGrade->id),
            'materia_token' => $subjectToken,
        ])->assertUnprocessable()->assertJsonValidationErrors('grado_token');

        $otherSchool = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => 'academic-other', 'name' => 'Otro colegio', 'slug' => 'academic-other',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        tenancy()->initialize($otherSchool);
        $otherTenantSubjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        tenancy()->initialize($this->school);
        $this->putJson($api.'/siee/'.$yearToken.'/curriculo', [
            'grado_token' => $gradeToken, 'materia_token' => $otherTenantSubjectToken,
        ])->assertUnprocessable()->assertJsonValidationErrors('materia_token');

        $options = $this->getJson($api.'/catalogos-academicos?opaque=1&tipo=materias&ano_lectivo_token='
            .$yearToken.'&compatible_nivel_token='.OpaqueUrlToken::for('nivel', $this->group->grado->nivel_id))
            ->assertOk()->json('data');
        $this->assertSame($subjectToken, $options[0]['url_token']);
        $this->assertArrayNotHasKey('id', $options[0]);
        $this->assertArrayNotHasKey('nivel_id', $options[0]);
        $this->getJson($api.'/catalogos-academicos?opaque=1&tipo=materias&ano_lectivo_token='
            .$yearToken.'&compatible_nivel_id='.$this->group->grado->nivel_id)
            ->assertUnprocessable()->assertJsonValidationErrors('compatible_nivel_id');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->getJson($api.'/catalogos-academicos?opaque=1&tipo=materias&ano_lectivo_token='.$yearToken)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url_token', $subjectToken);
    }

    public function test_sede_selector_is_opaque_and_legacy_links_still_resolve(): void
    {
        $sede = $this->group->sede;
        $legacy = rtrim(strtr(base64_encode((string) $sede->id), '+/', '-_'), '=');
        $token = $sede->hashed_id;
        $controller = app(SedeController::class);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $token);
        $this->assertSame(OpaqueUrlToken::for('sede', $sede->id), $token);
        $this->assertNotSame($legacy, $token);
        $this->assertSame($sede->id, $controller->show($token)->getData(true)['data']['id']);
        $this->assertSame($token, $controller->show($legacy)->getData(true)['data']['hashed_id']);
        $this->assertSame($sede->id, $controller->show((string) $sede->id)->getData(true)['data']['id']);
        $this->assertSame($sede->id, $sede->resolveRouteBinding($token)->id);
    }

    public function test_summary_period_uses_the_siee_annual_result_without_creating_a_real_period(): void
    {
        $this->year->update(['num_periodos' => 1, 'periodo_sumatorio' => true]);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        (new GradebookService)->saveGrades($this->teacher, $this->assignment, $period->id, [
            ['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '4', 'version' => 0],
        ]);

        $report = (new GradebookService)->report($enrollment);
        $this->assertSame(1, Periodo::where('ano_lectivo_id', $this->year->id)->count());
        $this->assertSame('P2', $report['periodo_sumatorio']['nombre']);
        $this->assertSame('calculado', $report['asignaturas'][0]['anual']['estado']);
        $this->assertSame('4.0', $report['asignaturas'][0]['anual']['display_value']);
    }

    public function test_summary_period_weights_subject_and_area_results_with_only_the_real_periods(): void
    {
        $this->year->update(['num_periodos' => 2, 'periodo_sumatorio' => true]);
        [$first, $enrollment, $firstActivity] = $this->gradeFixture();
        $first->update(['peso' => 20]);
        $second = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'P2',
            'orden' => 2, 'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-12-31',
            'peso' => 80, 'estado' => 'abierto']);
        $component = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id,
            'periodo_id' => $second->id, 'nombre' => 'Talleres', 'modo' => 'SIMPLE_AVERAGE']);
        $secondActivity = ActividadEvaluacion::create(['componente_id' => $component->id,
            'nombre' => 'Taller 2', 'fecha' => '2026-05-05']);
        $siee = $this->year->siee;
        $this->year->update(['siee' => [...$siee, 'modo_anual' => 'WEIGHTED_AVERAGE', 'usar_areas' => true]]);
        (new GradebookService)->saveGrades($this->teacher, $this->assignment, $first->id, [
            ['matricula_id' => $enrollment->id, 'actividad_id' => $firstActivity->id, 'valor' => '5', 'version' => 0],
        ]);
        (new GradebookService)->saveGrades($this->teacher, $this->assignment, $second->id, [
            ['matricula_id' => $enrollment->id, 'actividad_id' => $secondActivity->id, 'valor' => '3', 'version' => 0],
        ]);

        $report = (new GradebookService)->report($enrollment);
        $this->assertCount(2, $report['periodos']);
        $this->assertSame('P3', $report['periodo_sumatorio']['nombre']);
        $this->assertSame('3.4', $report['asignaturas'][0]['anual']['display_value']);
        $this->assertSame('3.4', $report['areas'][0]['anual']['display_value']);
    }

    public function test_copy_source_can_change_only_while_copied_configuration_is_pristine(): void
    {
        $service = app(DuplicarAnoLectivoService::class);
        $target = $service->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], [], $this->rector);
        $other = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A',
            'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 3]);
        Jornada::create(['ano_lectivo_id' => $other->id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'Tarde', 'hora_inicio' => '13:00', 'hora_fin' => '18:00', 'estado' => 'activa']);

        $service->copiarConfiguracion($this->year, $target, ['jornadas' => true], $this->rector);
        $this->assertTrue($service->copyStatus($target)['opciones']['jornadas']);
        $this->assertSame($this->year->id, $service->copyStatus($target)['origen_id']);
        $service->copiarConfiguracion($other, $target, ['jornadas' => true], $this->rector);
        $this->assertSame(['Tarde'], Jornada::where('ano_lectivo_id', $target->id)->pluck('nombre')->all());
        $this->assertSame($other->id, $service->copyStatus($target)['origen_id']);

        Jornada::where('ano_lectivo_id', $target->id)->firstOrFail()->update(['nombre' => 'Tarde ajustada']);
        $this->expectException(ValidationException::class);
        $service->copiarConfiguracion($this->year, $target, ['jornadas' => true], $this->rector);
    }

    public function test_legacy_extra_period_migration_archives_the_old_row_and_preserves_four_real_periods(): void
    {
        $this->year->update(['num_periodos' => 4, 'periodo_sumatorio' => true]);
        foreach ([
            ['2026-01-01', '2026-03-31'], ['2026-04-01', '2026-06-30'],
            ['2026-07-01', '2026-09-30'], ['2026-10-01', '2026-11-30'],
            ['2026-12-01', '2026-12-31'],
        ] as $index => [$start, $end]) {
            Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'P'.($index + 1),
                'orden' => $index + 1, 'fecha_inicio' => $start, 'fecha_fin' => $end,
                'peso' => 20, 'estado' => 'planificado']);
        }
        Schema::drop('periodos_sumatorios_legado');
        Schema::table('anos_lectivos', fn ($table) => $table->renameColumn('periodo_sumatorio', 'tiene_quinto_periodo'));
        $migration = require base_path('database/migrations/tenant/2026_09_29_000004_convert_legacy_extra_period_to_annual_result.php');
        $migration->up();
        (require base_path('database/migrations/tenant/2026_09_29_000006_audit_legacy_summary_period_conversion.php'))->up();

        $periods = Periodo::where('ano_lectivo_id', $this->year->id)->orderBy('orden')->get();
        $this->assertCount(4, $periods);
        $this->assertSame('2026-12-31', $periods->last()->fecha_fin->toDateString());
        $this->assertSame([25.0, 25.0, 25.0, 25.0], $periods->pluck('peso')->map(fn ($peso) => (float) $peso)->all());
        $this->assertSame(1, DB::table('periodos_sumatorios_legado')->count());
        $this->assertDatabaseHas('audit_logs', ['accion' => 'MIGRATE', 'recurso' => 'periodo_sumatorio', 'recurso_id' => (string) $this->year->id]);
        $this->assertTrue((bool) AnoLectivo::findOrFail($this->year->id)->periodo_sumatorio);
    }

    public function test_year_duplication_copies_siee_and_evaluation_catalog_with_new_references_only(): void
    {
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $this->year->id, 'calculo_nota' => 'promedio_simple', 'nota_minima' => '3', 'ambito' => 'materia']);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $target = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['grupos' => true, 'bloques' => true, 'espacios' => true, 'curriculo' => true, 'modelos' => true], $this->rector);

        $this->assertSame(AnoLectivo::ESTADO_PLANIFICADO, $target->estado);
        $newGroup = Grupo::where('ano_lectivo_id', $target->id)->firstOrFail();
        $newSubject = Materia::where('ano_lectivo_id', $target->id)->firstOrFail();
        $this->assertNotEquals($this->group->id, $newGroup->id);
        $this->assertNotEquals($this->subject->id, $newSubject->id);
        $this->assertSame($target->id, Grado::findOrFail($newGroup->grado_id)->ano_lectivo_id);
        $this->assertSame($target->id, Jornada::findOrFail($newGroup->jornada_id)->ano_lectivo_id);
        $this->assertSame($target->id, Area::findOrFail($newSubject->area_id)->ano_lectivo_id);
        $this->assertNotEquals($scale->id, $target->fresh()->siee['escala_id']);
        $this->assertNotEquals($method->id, $target->fresh()->siee['metodo_id']);
        $this->assertDatabaseHas('materias_curriculares', [
            'ano_lectivo_id' => $target->id, 'grado_id' => $newGroup->grado_id, 'materia_id' => $newSubject->id,
        ]);
        $this->assertSame(0, AsignacionDocente::where('ano_lectivo_id', $target->id)->count());
    }

    public function test_year_duplication_respects_selected_sections(): void
    {
        $target = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['jornadas' => true], $this->rector);

        $this->assertSame(1, Jornada::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(0, Nivel::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(0, Materia::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(0, Grupo::where('ano_lectivo_id', $target->id)->count());
        $this->assertNull($target->fresh()->siee);
    }

    public function test_periods_are_optional_on_duplication_and_can_be_copied_later(): void
    {
        Periodo::create([
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Primer período',
            'orden' => 1, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31',
            'peso' => 30, 'estado' => Periodo::ESTADO_CERRADO,
        ]);
        Periodo::create([
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Segundo período',
            'orden' => 2, 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-07-31',
            'peso' => 30, 'estado' => Periodo::ESTADO_PLANIFICADO,
        ]);
        $service = app(DuplicarAnoLectivoService::class);
        $target = $service->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], [], $this->rector);
        $this->assertSame(0, Periodo::where('ano_lectivo_id', $target->id)->count());

        $service->copiarConfiguracion($this->year, $target, ['periodos' => true], $this->rector);
        $service->copiarConfiguracion($this->year, $target, ['periodos' => true], $this->rector);
        $periods = Periodo::where('ano_lectivo_id', $target->id)->orderBy('orden')->get();
        $this->assertCount(2, $periods);
        $this->assertSame('Primer período', $periods[0]->nombre);
        $this->assertSame('2027-01-01', $periods[0]->fecha_inicio->toDateString());
        $this->assertSame('2027-03-31', $periods[0]->fecha_fin->toDateString());
        $this->assertSame(Periodo::ESTADO_PLANIFICADO, $periods[0]->estado);
        $this->assertEquals(30, $periods[0]->peso);

        $another = $service->duplicar($this->year, [
            'nombre' => '2028', 'tipo_calendario' => 'A', 'fecha_inicio' => '2028-01-01',
            'fecha_fin' => '2028-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['periodos' => true], $this->rector);
        $this->assertSame(2, Periodo::where('ano_lectivo_id', $another->id)->count());
        $this->assertSame('2028-03-31', Periodo::where('ano_lectivo_id', $another->id)->where('orden', 1)->firstOrFail()->fecha_fin->toDateString());
    }

    public function test_omitted_year_sections_can_be_copied_later_without_duplicate_records(): void
    {
        $service = app(DuplicarAnoLectivoService::class);
        $target = $service->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], [], $this->rector);

        $this->assertSame(0, Jornada::where('ano_lectivo_id', $target->id)->count());
        $service->copiarConfiguracion($this->year, $target, ['grupos' => true], $this->rector);
        $newGroup = Grupo::where('ano_lectivo_id', $target->id)->firstOrFail();
        $this->assertSame($target->id, Grado::findOrFail($newGroup->grado_id)->ano_lectivo_id);
        $this->assertSame($target->id, Jornada::findOrFail($newGroup->jornada_id)->ano_lectivo_id);

        $service->copiarConfiguracion($this->year, $target, ['grupos' => true, 'materias' => true], $this->rector);
        $this->assertSame(1, Grupo::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(1, Jornada::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(1, Materia::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(0, AsignacionDocente::where('ano_lectivo_id', $target->id)->count());
    }

    public function test_late_copy_preserves_existing_target_configuration_and_remaps_curriculum(): void
    {
        $service = app(DuplicarAnoLectivoService::class);
        $target = $service->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], ['grados' => true, 'materias' => true], $this->rector);
        $newSubject = Materia::where('ano_lectivo_id', $target->id)->firstOrFail();
        $newSubject->update(['intensidad_horaria' => 9]);

        $service->copiarConfiguracion($this->year, $target, ['curriculo' => true], $this->rector);
        $service->copiarConfiguracion($this->year, $target, ['curriculo' => true], $this->rector);

        $this->assertSame(9, $newSubject->fresh()->intensidad_horaria);
        $this->assertSame(1, Materia::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(1, DB::table('materias_curriculares')->where('ano_lectivo_id', $target->id)->count());
        $this->assertDatabaseHas('materias_curriculares', [
            'ano_lectivo_id' => $target->id, 'materia_id' => $newSubject->id,
        ]);
    }

    public function test_siee_can_be_copied_later_without_replacing_target_settings(): void
    {
        $scale = EscalaValorativa::create([
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica',
            'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1,
        ]);
        $method = MetodoAprobacion::create([
            'ano_lectivo_id' => $this->year->id, 'calculo_nota' => 'promedio_simple',
            'nota_minima' => '3', 'ambito' => 'materia',
        ]);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $service = app(DuplicarAnoLectivoService::class);
        $target = $service->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], [], $this->rector);

        $service->copiarConfiguracion($this->year, $target, ['siee' => true], $this->rector);
        $this->assertNotEquals($scale->id, $target->fresh()->siee['escala_id']);
        $this->assertNotEquals($method->id, $target->fresh()->siee['metodo_id']);
        $customSiee = $target->fresh()->siee;
        $customSiee['ponderacion_periodos'] = [30, 30, 40];
        $target->update(['siee' => $customSiee]);

        $service->copiarConfiguracion($this->year, $target, ['siee' => true], $this->rector);
        $this->assertSame([30, 30, 40], $target->fresh()->siee['ponderacion_periodos']);
        $this->assertSame(1, EscalaValorativa::where('ano_lectivo_id', $target->id)->count());
        $this->assertSame(1, MetodoAprobacion::where('ano_lectivo_id', $target->id)->count());
    }

    public function test_late_copy_rejects_a_closed_destination(): void
    {
        $target = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'periodo_sumatorio' => false,
        ], [], $this->rector);
        $target->update(['estado' => AnoLectivo::ESTADO_CERRADO]);

        $this->expectException(ValidationException::class);
        app(DuplicarAnoLectivoService::class)->copiarConfiguracion($this->year, $target, ['grupos' => true], $this->rector);
    }

    public function test_pending_assignment_migration_links_existing_classes_to_single_teacher(): void
    {
        $sessionId = DB::table('sesiones_horario')->insertGetId([
            'ano_lectivo_id' => $this->year->id,
            'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id,
            'asignacion_id' => null, 'docente_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/tenant/2026_09_29_000001_allow_pending_teacher_assignments.php');
        $migration->up();

        $this->assertDatabaseHas('sesiones_horario', [
            'id' => $sessionId, 'asignacion_id' => $this->assignment->id,
            'docente_id' => $this->teacher->id,
        ]);
    }

    public function test_catalog_migration_refuses_ambiguous_historical_years(): void
    {
        $old = AnoLectivo::create([
            'nombre' => '2025', 'tipo_calendario' => 'A', 'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2025-12-31', 'num_periodos' => 3, 'estado' => 'cerrado',
        ]);
        Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $old->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'Histórico', 'estado' => 'activo',
        ]);
        $migration = require database_path('migrations/tenant/2026_09_29_000002_scope_academic_catalogs_to_year.php');
        $this->expectException(\RuntimeException::class);
        $migration->up();
    }

    public function test_new_tenant_verifier_rejects_missing_migration(): void
    {
        DB::table('migrations')->where('migration', '2026_09_29_000002_scope_academic_catalogs_to_year')->delete();
        $this->expectException(\RuntimeException::class);
        (new VerifyTenantMigrations($this->school))->verifyCurrentDatabase();
    }

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
        // Los tests históricos de este módulo usan el contrato numérico antiguo.
        // La excepción existe únicamente en testing y requiere esta cabecera.
        $this->withHeader('X-Legacy-Academic-Ids', '1');
        // Conexión tenant real separada de la central, ambas SQLite en memoria.
        config(['tenancy.bootstrappers' => [], 'database.connections.academic_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'academic-test', 'name' => 'Colegio', 'slug' => 'academic-test', 'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active']));
        DB::setDefaultConnection('academic_test');
        Artisan::call('migrate', ['--database' => 'academic_test', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize($this->school);
        $teacherRole = Role::findOrCreate('docente', 'web');
        $rectorRole = Role::findOrCreate('rector', 'web');
        foreach (['notas.registrar_materia_asignada', 'eventos.publicar_asignados'] as $permission) {
            $teacherRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        foreach ([
            'academico.anos.transicionar', 'academico.periodos.transicionar',
            'notas.planilla.configurar', 'notas.actividades.crear', 'notas.actividades.editar', 'notas.actividades.eliminar',
            'notas.ver_consolidado_todos', 'notas.editar_no_dicta',
            'academico.matriculas.gestionar', 'eventos.gestionar', 'eventos.configurar',
            'eventos.publicar_institucional', 'eventos.publicar_asignados',
        ] as $permission) {
            $rectorRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->teacher = User::create(['name' => 'Docente', 'email' => 'teacher@test.test', 'password' => 'TeacherPassword123', 'role' => 'docente', 'status' => 'active']);
        $this->teacher->assignRole('docente');
        $this->rector = User::create(['name' => 'Rector', 'email' => 'rector@test.test', 'password' => 'RectorPassword123', 'role' => 'rector', 'status' => 'active']);
        $this->rector->assignRole('rector');
        $this->year = AnoLectivo::create(['nombre' => '2026', 'tipo_calendario' => 'A', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'num_periodos' => 3, 'estado' => 'en_curso']);
        $sede = Sede::create(['nombre' => 'Norte', 'estado' => 'activa']);
        $journey = Jornada::create(['ano_lectivo_id' => $this->year->id, 'sede_id' => $sede->id, 'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '13:00', 'estado' => 'activa']);
        $level = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $grade = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'codigo' => '01', 'estado' => 'activo']);
        $this->group = Grupo::create(['grado_id' => $grade->id, 'ano_lectivo_id' => $this->year->id, 'nombre' => 'A', 'jornada_id' => $journey->id, 'sede_id' => $sede->id, 'estado' => 'activo']);
        $area = Area::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
        $this->subject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Biología', 'area_id' => $area->id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $grade->id, 'materia_id' => $this->subject->id, 'area_id' => $area->id,
            'peso_area' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->assignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id, 'docente_id' => $this->teacher->id]);
        $this->block = BloqueHorario::create(['ano_lectivo_id' => $this->year->id, 'jornada_id' => $journey->id, 'nombre' => 'Primera', 'hora_inicio' => '08:00', 'hora_fin' => '09:00', 'estado' => 'activo', 'es_descanso' => false]);
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
        $this->assertDatabaseHas('sesiones_horario', ['id' => $session->id, 'ano_lectivo_id' => $this->year->id]);
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
            $message = $exception->errors()['horario'][0];
            $this->assertStringContainsString('grupo', $message);
            $this->assertStringContainsString($this->subject->nombre, $message);
            $this->assertStringContainsString($this->group->grado->nombre, $message);
            $this->assertStringContainsString('lunes de 08:00 a '.substr($this->block->hora_fin, 0, 5), $message);
            $this->assertStringNotContainsString('clase #', $message);
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

    public function test_dragging_moves_a_class_and_copying_creates_another_with_the_same_assignment(): void
    {
        $controller = app(HorarioController::class);
        $request = function (string $method, array $data): Request {
            $request = Request::create('/api/horarios', $method, $data);
            $request->setUserResolver(fn () => $this->rector);

            return $request;
        };
        $class = (new HorarioService)->guardar([
            'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $shared = ['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'espacio_fisico_id' => null];

        $moved = $controller->guardar($request('PUT', [...$shared,
            'dia' => 'martes', 'bloque_horario_id' => $this->block->id,
            'hora_inicio' => null, 'hora_fin' => null,
        ]), new HorarioService, $class->id);
        $this->assertSame(200, $moved->status());
        $this->assertSame($class->id, $moved->getData(true)['data']['id']);
        $this->assertSame(1, SesionHorario::count());

        $copy = $controller->guardar($request('POST', [...$shared,
            'dia' => 'miercoles', 'bloque_horario_id' => null,
            'hora_inicio' => '09:15', 'hora_fin' => '10:15',
        ]), new HorarioService);
        $this->assertSame(201, $copy->status());
        $this->assertSame($this->assignment->id, $copy->getData(true)['data']['asignacion_id']);
        $this->assertSame(2, SesionHorario::count());
        $this->assertSame(1, AsignacionDocente::count());

        try {
            $controller->guardar($request('POST', [...$shared,
                'dia' => 'martes', 'bloque_horario_id' => $this->block->id,
            ]), new HorarioService);
            $this->fail('No se puede copiar una clase sobre otra del mismo grupo.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('grupo', $exception->errors()['horario'][0]);
        }
        $this->assertSame(2, SesionHorario::count());
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
        $otherSubject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Lenguaje inicial', 'area_id' => $this->subject->area_id, 'nivel_id' => $preescolar->id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $prejardin->id, 'materia_id' => $otherSubject->id, 'area_id' => $otherSubject->area_id]);
        $otherAssignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $otherGroup->id, 'materia_id' => $otherSubject->id, 'docente_id' => $otherTeacher->id]);

        $service = new HorarioService;
        $first = $service->guardar(['asignacion_id' => $this->assignment->id, 'dia' => 'lunes', 'hora_inicio' => '07:00', 'hora_fin' => '07:45'], $this->rector);
        $second = $service->guardar(['asignacion_id' => $otherAssignment->id, 'dia' => 'lunes', 'hora_inicio' => '07:20', 'hora_fin' => '08:00'], $this->rector);

        $this->assertNull($first->bloque_horario_id);
        $this->assertNull($second->bloque_horario_id);
        $this->assertSame('07:20', substr($second->hora_inicio, 0, 5));
    }

    public function test_schedule_reuses_teacher_assignment_and_can_be_edited(): void
    {
        $service = new HorarioService;
        $class = $service->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => null,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $this->assertSame($this->assignment->id, $class->asignacion_id);
        $this->assertSame($this->teacher->id, $class->docente_id);
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

    public function test_moving_the_only_class_updates_its_assignment_instead_of_leaving_a_duplicate(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $service = new HorarioService;
        $class = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $moved = $service->guardar(['grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector, $class);

        $this->assertSame($this->assignment->id, $moved->asignacion_id);
        $this->assertSame($otherGroup->id, $this->assignment->fresh()->grupo_id);
        $this->assertSame(1, AsignacionDocente::count());
        $this->assertDatabaseHas('sesiones_horario', ['id' => $class->id, 'grupo_id' => $otherGroup->id,
            'asignacion_id' => $this->assignment->id]);
    }

    public function test_editing_an_assignment_moves_its_classes_and_keeps_the_same_assignment_id(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $otherSubject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Matemáticas',
            'area_id' => $this->subject->area_id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $this->group->grado_id, 'materia_id' => $otherSubject->id, 'area_id' => $otherSubject->area_id]);
        $service = new HorarioService;
        $class = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $request = Request::create('/api/asignaciones/'.$this->assignment->id, 'PUT', [
            'grupo_id' => $otherGroup->id, 'materia_id' => $otherSubject->id, 'docente_id' => $this->teacher->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);
        $response = app(HorarioController::class)->editarAsignacion($request, $this->assignment->id,
            app(\App\Services\AsignacionHorarioService::class), $service);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->assignment->id, $response->getData(true)['data']['id']);
        $this->assertSame(1, AsignacionDocente::count());
        $this->assertDatabaseHas('sesiones_horario', ['id' => $class->id, 'grupo_id' => $otherGroup->id,
            'materia_id' => $otherSubject->id, 'asignacion_id' => $this->assignment->id]);
    }

    public function test_moving_one_of_two_classes_keeps_the_old_assignment_for_the_remaining_class(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $service = new HorarioService;
        $monday = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $wednesday = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'miercoles', 'bloque_horario_id' => $this->block->id], $this->rector);
        $service->guardar(['grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector, $monday);

        $this->assertSame(2, AsignacionDocente::count());
        $this->assertSame($this->assignment->id, $wednesday->fresh()->asignacion_id);
        $this->assertNotSame($this->assignment->id, $monday->fresh()->asignacion_id);
    }

    public function test_moving_the_only_class_to_an_existing_assignment_reuses_target_and_removes_empty_source(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $target = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id,
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id]);
        $service = new HorarioService;
        $class = $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $service->guardar(['grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id], $this->rector, $class);

        $this->assertSame($target->id, $class->fresh()->asignacion_id);
        $this->assertSame(1, AsignacionDocente::count());
        $this->assertSoftDeleted('asignaciones_docentes', ['id' => $this->assignment->id]);
    }

    public function test_assignment_with_evaluation_cannot_be_relabelled_as_another_group(): void
    {
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $this->group->jornada_id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'P1', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-31', 'estado' => 'abierto']);
        ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id,
            'periodo_id' => $period->id, 'nombre' => 'Talleres', 'modo' => 'SIMPLE_AVERAGE']);
        $request = Request::create('/api/asignaciones/'.$this->assignment->id, 'PUT', [
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(HorarioController::class)->editarAsignacion($request, $this->assignment->id,
                app(\App\Services\AsignacionHorarioService::class), new HorarioService);
            $this->fail('Una evaluación existente no debe cambiar de grupo.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($this->group->id, $this->assignment->fresh()->grupo_id);
    }

    public function test_assignment_edit_rolls_back_when_existing_block_does_not_fit_the_new_group(): void
    {
        $otherJourney = Jornada::create(['ano_lectivo_id' => $this->year->id,
            'sede_id' => $this->group->sede_id, 'nombre' => 'Tarde',
            'hora_inicio' => '13:00', 'hora_fin' => '18:00', 'estado' => 'activa']);
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'jornada_id' => $otherJourney->id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'B', 'estado' => 'activo',
        ]);
        $class = (new HorarioService)->guardar(['grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id, 'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id], $this->rector);
        $request = Request::create('/api/asignaciones/'.$this->assignment->id, 'PUT', [
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(HorarioController::class)->editarAsignacion($request, $this->assignment->id,
                app(\App\Services\AsignacionHorarioService::class), new HorarioService);
            $this->fail('El bloque de la jornada original no debe trasladarse a otra jornada.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($this->group->id, $this->assignment->fresh()->grupo_id);
        $this->assertSame($this->group->id, $class->fresh()->grupo_id);
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
        $this->assertNotNull($response->getData(true)['data']['asignacion_id']);
        $this->assertDatabaseHas('asignaciones_docentes', [
            'ano_lectivo_id' => $this->year->id,
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => null,
        ]);
        $this->assertDatabaseHas('sesiones_horario', ['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id]);
    }

    public function test_teacher_selected_in_assignments_updates_every_class_of_the_same_year_group_and_subject(): void
    {
        $this->assignment->update(['docente_id' => null]);
        $service = new HorarioService;
        $lunes = $service->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $miercoles = $service->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'dia' => 'miercoles',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);

        $request = Request::create('/api/asignaciones', 'POST', [
            'ano_lectivo_id' => $this->year->id,
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);
        $response = app(HorarioController::class)->asignar($request, app(\App\Services\AsignacionHorarioService::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->teacher->id, $this->assignment->fresh()->docente_id);
        $this->assertSame($this->teacher->id, $lunes->fresh()->docente_id);
        $this->assertSame($this->teacher->id, $miercoles->fresh()->docente_id);
        $this->assertSame($this->assignment->id, $lunes->fresh()->asignacion_id);
        $this->assertSame($this->assignment->id, $miercoles->fresh()->asignacion_id);
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
        $this->assignment->update(['docente_id' => null]);
        $class = (new HorarioService)->guardar([
            'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id,
            'docente_id' => null,
            'dia' => 'viernes',
            'hora_inicio' => '07:00',
            'hora_fin' => '07:45',
        ], $this->rector);
        $request = Request::create('/api/horarios?vista=horarios');
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

    public function test_schedule_view_requires_a_group_for_managers_and_filters_on_the_server(): void
    {
        $this->rector->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(
            'academico.plan_estudios.gestionar', 'web'));
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'nombre' => 'B', 'jornada_id' => $this->group->jornada_id,
            'sede_id' => $this->group->sede_id, 'estado' => 'activo',
        ]);
        $otherAssignment = AsignacionDocente::create([
            'ano_lectivo_id' => $this->year->id, 'grupo_id' => $otherGroup->id,
            'materia_id' => $this->subject->id, 'docente_id' => null,
        ]);
        $first = (new HorarioService)->guardar([
            'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $second = (new HorarioService)->guardar([
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => null, 'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $request = function (string $query): Request {
            $request = Request::create('/api/horarios?ano_lectivo_id='.$this->year->id.$query);
            $request->setUserResolver(fn () => $this->rector);

            return $request;
        };
        $controller = app(HorarioController::class);

        $unselected = $controller->index($request('&vista=horarios'))->getData(true)['data'];
        $this->assertTrue($unselected['can_manage']);
        $this->assertSame([], $unselected['asignaciones']);
        $this->assertSame([], $unselected['sesiones']);
        $this->assertEqualsCanonicalizing([$this->group->id, $otherGroup->id], array_column($unselected['grupos'], 'id'));

        $selected = $controller->index($request('&vista=horarios&grupo_id='.$this->group->id))->getData(true)['data'];
        $this->assertSame([$this->assignment->id], array_column($selected['asignaciones'], 'id'));
        $this->assertSame([$first->id], array_column($selected['sesiones'], 'id'));

        $otherSelected = $controller->index($request('&vista=horarios&grupo_id='.$otherGroup->id))->getData(true)['data'];
        $this->assertSame([$otherAssignment->id], array_column($otherSelected['asignaciones'], 'id'));
        $this->assertSame([$second->id], array_column($otherSelected['sesiones'], 'id'));

        $otherTabs = $controller->index($request(''))->getData(true)['data'];
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($otherTabs['sesiones'], 'id'));
    }

    public function test_schedule_summary_counts_follow_group_and_teacher_filters(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $otherTeacher = User::create(['name' => 'Otra docente', 'email' => 'other.teacher@test.test',
            'password' => 'TeacherPassword123', 'role' => 'docente', 'status' => 'active']);
        $otherTeacher->assignRole('docente');
        $otherGroup = Grupo::create([
            'grado_id' => $this->group->grado_id, 'ano_lectivo_id' => $this->year->id,
            'nombre' => 'B', 'jornada_id' => $this->group->jornada_id,
            'sede_id' => $this->group->sede_id, 'estado' => 'activo',
        ]);
        $otherSubject = Materia::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Química', 'intensidad_horaria' => 1, 'estado' => 'activo']);
        $directSubject = Materia::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Arte', 'intensidad_horaria' => 1, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $this->group->grado_id, 'materia_id' => $otherSubject->id, 'area_id' => $otherSubject->area_id]);
        Materia::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Sin programación', 'intensidad_horaria' => 1, 'estado' => 'activo']);
        AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $otherGroup->id,
            'materia_id' => $otherSubject->id, 'docente_id' => $otherTeacher->id]);
        AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $otherGroup->id,
            'materia_id' => $this->subject->id, 'docente_id' => $this->teacher->id]);
        $service = new HorarioService;
        $service->guardar(['grupo_id' => $this->group->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $service->guardar(['grupo_id' => $otherGroup->id, 'materia_id' => $otherSubject->id,
            'docente_id' => $otherTeacher->id, 'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        SesionHorario::create(['ano_lectivo_id' => $this->year->id, 'asignacion_id' => null,
            'grupo_id' => $this->group->id, 'materia_id' => $directSubject->id,
            'docente_id' => $otherTeacher->id, 'dia' => 'martes', 'bloque_horario_id' => $this->block->id]);

        $summary = function (string $query): array {
            $request = Request::create('/api/horarios?ano_lectivo_id='.$this->year->id.$query);
            $request->setUserResolver(fn () => $this->rector);

            return app(HorarioController::class)->index($request)->getData(true)['data'];
        };
        $all = $summary('');
        $this->assertSame([4, 3, 3], [$all['counts']['materias'], $all['pagination']['asignaciones']['total'], $all['counts']['sesiones']]);

        $group = $summary('&grupo_id='.$this->group->id);
        $this->assertSame([2, 1, 2], [$group['counts']['materias'], $group['pagination']['asignaciones']['total'], $group['counts']['sesiones']]);
        $this->assertSame(OpaqueUrlToken::for('grupo', $this->group->id),
            collect($group['grupos'])->firstWhere('id', $this->group->id)['url_token']);

        $teacher = $summary('&docente_id='.$this->teacher->id);
        $this->assertSame([1, 2, 1], [$teacher['counts']['materias'], $teacher['pagination']['asignaciones']['total'], $teacher['counts']['sesiones']]);
        $this->assertSame(OpaqueUrlToken::for('docente', $this->teacher->id),
            collect($teacher['docentes'])->firstWhere('id', $this->teacher->id)['url_token']);

        $direct = $summary('&grupo_id='.$this->group->id.'&docente_id='.$otherTeacher->id);
        $this->assertSame([1, 0, 1], [$direct['counts']['materias'], $direct['pagination']['asignaciones']['total'], $direct['counts']['sesiones']]);
    }

    public function test_schedule_filter_catalogs_expose_stable_distinct_opaque_url_tokens(): void
    {
        $this->rector->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(
            'academico.plan_estudios.gestionar', 'web'));
        $space = EspacioFisico::create([
            'ano_lectivo_id' => $this->year->id,
            'sede_id' => $this->group->sede_id,
            'nombre' => 'Aula 1',
            'tipo' => EspacioFisico::TIPO_AULA,
            'estado' => EspacioFisico::ESTADO_DISPONIBLE,
        ]);
        $request = Request::create('/api/horarios?ano_lectivo_id='.$this->year->id.'&vista=horarios');
        $request->setUserResolver(fn () => $this->rector);
        $controller = app(HorarioController::class);
        $first = $controller->index($request)->getData(true)['data'];
        $again = $controller->index($request)->getData(true)['data'];

        foreach ([
            ['grupos', $this->group->id, 'grupo'],
            ['espacios', $space->id, 'espacio-fisico'],
            ['docentes', $this->teacher->id, 'docente'],
        ] as [$catalog, $id, $resource]) {
            $entry = collect($first[$catalog])->firstWhere('id', $id);
            $this->assertNotNull($entry);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $entry['url_token']);
            $this->assertSame(OpaqueUrlToken::for($resource, $id), $entry['url_token']);
            $this->assertSame($entry['url_token'], collect($again[$catalog])->firstWhere('id', $id)['url_token']);
        }
        $this->assertCount(3, array_unique([
            $first['grupos'][0]['url_token'],
            $first['espacios'][0]['url_token'],
            $first['docentes'][0]['url_token'],
        ]));
    }

    public function test_evaluation_catalog_exposes_opaque_url_tokens_for_detail_routes(): void
    {
        [$period, $enrollment] = $this->gradeFixture();
        $request = Request::create('/api/evaluacion/catalogo');
        $request->setUserResolver(fn () => $this->rector);
        $catalog = app(EvaluacionController::class)->catalogo($request)->getData(true)['data'];

        foreach ([
            ['asignaciones', $this->assignment->id, 'asignacion-docente'],
            ['periodos', $period->id, 'periodo'],
            ['matriculas', $enrollment->id, 'matricula'],
        ] as [$collection, $id, $type]) {
            $entry = collect($catalog[$collection])->firstWhere('id', $id);
            $this->assertNotNull($entry);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $entry['url_token']);
            $this->assertSame(OpaqueUrlToken::for($type, $id), $entry['url_token']);
        }
    }

    public function test_evaluation_and_event_details_accept_tenant_bound_opaque_route_selectors(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment] = $this->gradeFixture();
        $event = Evento::create(['titulo' => 'Circular', 'descripcion' => 'Aviso',
            'fecha' => '2026-09-25', 'created_by' => $this->rector->id, 'institucional' => true]);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $eventToken = OpaqueUrlToken::for('evento', $event->id);

        $this->getJson("{$api}/evaluacion/planillas/{$assignmentToken}/{$periodToken}")
            ->assertOk()->assertJsonPath('data.matriculas.0.url_token', $enrollmentToken);
        $this->getJson("{$api}/evaluacion/boletines/{$enrollmentToken}")->assertOk();
        $this->getJson("{$api}/eventos/{$eventToken}")
            ->assertOk()->assertJsonPath('data.url_token', $eventToken);
        $this->getJson("{$api}/eventos/{$assignmentToken}")->assertNotFound();
        $this->getJson("{$api}/evaluacion/planillas/{$periodToken}/{$periodToken}")->assertNotFound();

        $otherSchool = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => 'other-academic-routes', 'name' => 'Otro colegio', 'slug' => 'other-academic-routes',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        tenancy()->initialize($otherSchool);
        $foreignEventToken = OpaqueUrlToken::for('evento', $event->id);
        tenancy()->initialize($this->school);
        $this->getJson("{$api}/eventos/{$foreignEventToken}")->assertNotFound();
    }

    public function test_structure_and_study_plan_accept_tokens_without_cross_resource_resolution(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.estructura.gestionar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $gradeToken = OpaqueUrlToken::for('grado', $this->group->grado_id);
        $areaToken = OpaqueUrlToken::for('area', $this->subject->area_id);

        $this->getJson("{$api}/estructura/grados/{$gradeToken}")
            ->assertOk()->assertJsonPath('data.url_token', $gradeToken);
        $this->getJson("{$api}/estructura/grados/{$gradeToken}?opaque=1")
            ->assertOk()->assertJsonMissingPath('data.id');
        $this->getJson("{$api}/estructura/grados/{$this->group->grado_id}?opaque=1")
            ->assertNotFound();
        $this->getJson("{$api}/estructura/grados/{$areaToken}")->assertNotFound();
        $this->putJson("{$api}/plan-estudios/areas/{$areaToken}", [
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Ciencias Naturales',
        ])->assertOk()->assertJsonPath('data.url_token', $areaToken);
        $this->getJson("{$api}/plan-estudios/areas")
            ->assertOk()->assertJsonPath('data.0.url_token', $areaToken);
    }

    public function test_academic_year_and_period_routes_accept_their_own_opaque_selectors(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.anos.gestionar', 'web'));
        [$period] = $this->gradeFixture();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);

        $this->getJson("{$api}/anos-lectivos/{$yearToken}")->assertOk();
        $this->getJson("{$api}/anos-lectivos/{$yearToken}/periodos")
            ->assertOk()->assertJsonPath('data.0.url_token', $periodToken);
        $this->getJson("{$api}/anos-lectivos/{$periodToken}")->assertNotFound();
    }

    public function test_structure_and_plan_opaque_mode_uses_only_tenant_scoped_public_identifiers(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.estructura.gestionar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $areaToken = OpaqueUrlToken::for('area', $this->subject->area_id);
        $levelToken = OpaqueUrlToken::for('nivel', $this->group->grado->nivel_id);

        $this->getJson("{$api}/estructura/grupos?opaque=1&ano_lectivo_token={$yearToken}")
            ->assertOk()->assertJsonPath('data.0.url_token', OpaqueUrlToken::for('grupo', $this->group->id))
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.grado_id')
            ->assertJsonPath('data.0.grado_token', OpaqueUrlToken::for('grado', $this->group->grado_id));
        $this->getJson("{$api}/estructura/sedes?opaque=1")
            ->assertOk()->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.tenant_id');
        $this->getJson("{$api}/estructura/sedes/{$this->group->sede_id}?opaque=1")->assertNotFound();
        $sedeToken = OpaqueUrlToken::for('sede', $this->group->sede_id);
        $this->postJson("{$api}/estructura/sedes/{$sedeToken}/heredar?opaque=1")
            ->assertUnprocessable(); // La sede principal se resolvió; no tiene tenant hijo al cual heredar.
        $this->postJson("{$api}/estructura/sedes/{$this->group->sede_id}/heredar?opaque=1")
            ->assertNotFound();
        $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}&area_token={$areaToken}")
            ->assertOk()->assertJsonPath('data.0.url_token', OpaqueUrlToken::for('materia', $this->subject->id))
            ->assertJsonPath('data.0.area_token', $areaToken)
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.area.id');

        $this->postJson("{$api}/plan-estudios/materias?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'area_token' => $areaToken,
            'nivel_token' => $levelToken, 'nombre' => 'Química', 'intensidad_horaria' => 3,
        ])->assertCreated()->assertJsonMissingPath('data.id')
            ->assertJsonPath('data.ano_lectivo_token', $yearToken)
            ->assertJsonPath('data.area_token', $areaToken)
            ->assertJsonPath('data.nivel_token', $levelToken);
        $this->postJson("{$api}/plan-estudios/materias?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'area_token' => $areaToken,
            'nombre' => 'Ética y Valores', 'intensidad_horaria' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('nivel_id');
        $this->postJson("{$api}/plan-estudios/materias?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'area_token' => $areaToken, 'nivel_token' => '',
            'nombre' => 'Ética y Valores', 'intensidad_horaria' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('nivel_id');
        $this->postJson("{$api}/plan-estudios/materias?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'area_token' => $areaToken, 'nivel_token' => null,
            'nombre' => 'Ética y Valores', 'intensidad_horaria' => 2,
        ])->assertCreated()->assertJsonPath('data.nivel_token', null);
        $general = $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}&nivel_general=1")
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Biología', 'Ética y Valores'], array_column($general, 'nombre'));
        $primary = $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}&nivel_token={$levelToken}")
            ->assertOk()->json('data');
        $this->assertSame(['Química'], array_column($primary, 'nombre'));
        $all = $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}")
            ->assertOk()->json('data');
        $this->assertCount(3, $all);
        $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}&nivel_general=1&nivel_token={$levelToken}")
            ->assertUnprocessable();
        $this->postJson("{$api}/estructura/grupos?opaque=1", [
            'ano_lectivo_token' => $yearToken,
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'jornada_token' => OpaqueUrlToken::for('jornada', $this->group->jornada_id),
            'sede_token' => OpaqueUrlToken::for('sede', $this->group->sede_id),
            'nombre' => 'B',
        ])->assertCreated()->assertJsonMissingPath('data.id')
            ->assertJsonPath('data.ano_lectivo_token', $yearToken)
            ->assertJsonPath('data.grado_token', OpaqueUrlToken::for('grado', $this->group->grado_id));
        $this->postJson("{$api}/plan-estudios/materias?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'area_id' => $this->subject->area_id,
            'nombre' => 'Física', 'intensidad_horaria' => 3,
        ])->assertUnprocessable();
        $this->getJson("{$api}/plan-estudios/materias?opaque=1&ano_lectivo_token={$yearToken}&area_token={$levelToken}")
            ->assertUnprocessable();
    }

    public function test_opaque_academic_options_cover_structure_and_plan_without_numeric_ids(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.estructura.gestionar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/catalogos-academicos?opaque=1';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $sedeToken = OpaqueUrlToken::for('sede', $this->group->sede_id);
        $journeyToken = OpaqueUrlToken::for('jornada', $this->group->jornada_id);
        $levelToken = OpaqueUrlToken::for('nivel', $this->group->grado->nivel_id);
        $gradeToken = OpaqueUrlToken::for('grado', $this->group->grado_id);
        $areaToken = OpaqueUrlToken::for('area', $this->subject->area_id);

        foreach ([
            ['sedes', '', $sedeToken],
            ['jornadas', "&ano_lectivo_token={$yearToken}&sede_token={$sedeToken}", $journeyToken],
            ['niveles', "&ano_lectivo_token={$yearToken}", $levelToken],
            ['grados', "&ano_lectivo_token={$yearToken}&nivel_token={$levelToken}", $gradeToken],
            ['grupos', "&ano_lectivo_token={$yearToken}&grado_token={$gradeToken}", OpaqueUrlToken::for('grupo', $this->group->id)],
            ['areas', "&ano_lectivo_token={$yearToken}", $areaToken],
            ['materias', "&ano_lectivo_token={$yearToken}&area_token={$areaToken}", OpaqueUrlToken::for('materia', $this->subject->id)],
        ] as [$type, $filters, $expected]) {
            $this->getJson("{$api}&tipo={$type}{$filters}")
                ->assertOk()->assertJsonPath('data.0.url_token', $expected)
                ->assertJsonMissingPath('data.0.id');
        }
        $this->getJson("{$api}&tipo=grados&ano_lectivo_token={$yearToken}&nivel_id={$this->group->grado->nivel_id}")
            ->assertUnprocessable();
        $this->getJson("{$api}&tipo=jornadas&ano_lectivo_token={$yearToken}&sede_token={$levelToken}")
            ->assertUnprocessable();
    }

    public function test_all_structure_and_plan_lists_remove_internal_and_foreign_ids_in_opaque_mode(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.estructura.gestionar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        foreach ([
            'estructura/sedes', 'estructura/jornadas', 'estructura/niveles', 'estructura/grados',
            'estructura/grupos', 'estructura/bloques-horarios', 'estructura/espacios-fisicos',
            'plan-estudios/areas', 'plan-estudios/materias',
        ] as $path) {
            $data = $this->getJson("http://localhost/api/{$path}?opaque=1&ano_lectivo_token={$yearToken}")
                ->assertOk()->json('data');
            $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id|[a-z_]+_ids)"\s*:/',
                json_encode($data, JSON_THROW_ON_ERROR), $path);
        }
    }

    public function test_schedule_group_must_belong_to_selected_year(): void
    {
        $this->rector->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(
            'academico.plan_estudios.gestionar', 'web'));
        $otherYear = AnoLectivo::create([
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 3, 'estado' => 'planificado',
        ]);
        $request = Request::create('/api/horarios?ano_lectivo_id='.$otherYear->id.'&vista=horarios&grupo_id='.$this->group->id);
        $request->setUserResolver(fn () => $this->rector);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('El grupo no pertenece al año lectivo seleccionado.');
        app(HorarioController::class)->index($request);
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

    public function test_assignment_with_scheduled_classes_cannot_be_deleted(): void
    {
        $class = (new HorarioService)->guardar([
            'asignacion_id' => $this->assignment->id,
            'dia' => 'lunes',
            'bloque_horario_id' => $this->block->id,
        ], $this->rector);
        $request = Request::create('/api/asignaciones/'.$this->assignment->id, 'DELETE');
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(HorarioController::class)->desasignar($request, $this->assignment->id);
            $this->fail('No debe borrarse la asignación con clases.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($this->assignment->id, $class->fresh()->asignacion_id);
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
        $file = StoredFile::query()->where('tenant_id', $this->school->id)->firstOrFail();
        $this->assertSame(\App\Support\Storage\StoredFilePublicToken::for($file),
            $response->getData(true)['data']['url_token']);
        $this->assertArrayNotHasKey('id', $response->getData(true)['data']);
        $this->assertSame('Contenido de prueba del evento.', Storage::disk('tenant')->get($file->path));
        $this->assertDatabaseHas('evento_archivos', ['evento_id' => $event->id, 'stored_file_id' => $file->id]);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'evento', 'accion' => 'UPLOAD', 'recurso_id' => (string) $event->id]);
        $detail = $controller->show($request, $event->id)->getData(true)['data'];
        $this->assertSame(\App\Support\Storage\StoredFilePublicToken::for($file), $detail['archivos'][0]['url_token']);
        $this->assertArrayNotHasKey('id', $detail['archivos'][0]);
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

    public function test_event_permissions_remain_separate_and_assignment_scope_is_enforced(): void
    {
        $rectorRole = Role::findByName('rector', 'web');
        $rectorRole->revokePermissionTo('eventos.publicar_institucional');
        $rectorRole->revokePermissionTo('eventos.configurar');
        $this->rector = $this->rector->fresh();
        $this->assertTrue($this->rector->can('eventos.gestionar'));

        $access = new EventAccess;
        $institutional = Evento::create(['titulo' => 'Circular', 'descripcion' => 'Aviso',
            'fecha' => '2026-09-25', 'created_by' => $this->rector->id, 'institucional' => true]);
        foreach ([
            ['institucional' => true, 'grupo_ids' => []],
            ['institucional' => false, 'grupo_ids' => [$this->group->id]],
        ] as $data) {
            try {
                $access->authorizeWrite($this->rector, $data, $institutional);
                $this->fail('Gestionar eventos no permite publicar ni modificar un evento institucional.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $request = Request::create('/api/eventos/configuracion', 'PUT', ['docentes_cualquier_grupo' => true]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(EventoController::class)->configurar($request);
            $this->fail('Gestionar eventos no permite cambiar la política de publicación docente.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $other = User::create(['name' => 'Otra docente', 'email' => 'other-events@test.test',
            'password' => 'Password12345', 'role' => 'docente', 'status' => 'active']);
        $other->assignRole('docente');
        try {
            $access->authorizeWrite($other, ['institucional' => false,
                'grupo_ids' => [$this->group->id], 'materia_id' => $this->subject->id]);
            $this->fail('Publicar eventos asignados exige una asignación de grupo y materia.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        Role::findByName('docente', 'web')->revokePermissionTo('eventos.publicar_asignados');
        $this->teacher = $this->teacher->fresh();
        try {
            $access->authorizeWrite($this->teacher, ['institucional' => false,
                'grupo_ids' => [$this->group->id], 'materia_id' => $this->subject->id]);
            $this->fail('La asignación no permite publicar si se revocó el permiso.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_event_opaque_mode_accepts_group_tokens_without_exposing_internal_ids(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/eventos';
        $groupToken = OpaqueUrlToken::for('grupo', $this->group->id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        $catalog = $this->getJson("{$api}/catalogo?opaque=1")
            ->assertOk()->assertJsonPath('data.grupos.0.url_token', $groupToken)
            ->assertJsonMissingPath('data.grupos.0.id')->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id|created_by)"\s*:/',
            json_encode($catalog, JSON_THROW_ON_ERROR));

        $created = $this->postJson("{$api}?opaque=1", [
            'titulo' => 'Feria de ciencias', 'descripcion' => 'Presentación de proyectos',
            'fecha' => '2026-09-25', 'categoria' => 'actividad', 'institucional' => false,
            'grupo_tokens' => [$groupToken], 'materia_token' => $subjectToken,
        ])->assertCreated()->assertJsonMissingPath('data.id')
            ->assertJsonPath('data.grupo_tokens.0', $groupToken)
            ->assertJsonPath('data.materia_token', $subjectToken)->json('data');
        $eventToken = $created['url_token'];

        $this->getJson("{$api}?opaque=1&desde=2026-09-01&hasta=2026-09-30&grupo_token={$groupToken}")
            ->assertOk()->assertJsonPath('data.0.url_token', $eventToken)
            ->assertJsonMissingPath('data.0.id');
        $this->getJson("{$api}/{$eventToken}?opaque=1")
            ->assertOk()->assertJsonPath('data.url_token', $eventToken)
            ->assertJsonMissingPath('data.created_by');
        $this->putJson("{$api}/{$eventToken}?opaque=1", [
            'titulo' => 'Feria actualizada', 'descripcion' => 'Presentación de proyectos',
            'fecha' => '2026-09-25', 'categoria' => 'actividad', 'institucional' => false,
            'grupo_tokens' => [$groupToken], 'materia_token' => $subjectToken,
        ])->assertOk()->assertJsonPath('data.titulo', 'Feria actualizada');

        $this->getJson("{$api}/".Evento::firstOrFail()->id.'?opaque=1')->assertNotFound();
        $this->postJson("{$api}?opaque=1", [
            'titulo' => 'ID oculto', 'descripcion' => 'No procede', 'fecha' => '2026-09-25',
            'categoria' => 'actividad', 'institucional' => false,
            'grupo_ids' => [$this->group->id],
        ])->assertUnprocessable();
        $this->getJson("{$api}?opaque=1&desde=2026-09-01&hasta=2026-09-30&grupo_token={$subjectToken}")
            ->assertUnprocessable();

    }

    public function test_event_opaque_writes_still_enforce_owner_institutional_and_teacher_assignment(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $event = Evento::create(['titulo' => 'Circular', 'descripcion' => 'Aviso',
            'fecha' => '2026-09-25', 'categoria' => 'actividad',
            'created_by' => $this->rector->id, 'institucional' => false]);
        $event->grupos()->attach($this->group->id);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->teacher->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/eventos';
        $eventToken = OpaqueUrlToken::for('evento', $event->id);
        $groupToken = OpaqueUrlToken::for('grupo', $this->group->id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);

        $this->putJson("{$api}/{$eventToken}?opaque=1", [
            'titulo' => 'Cambio ajeno', 'descripcion' => 'No procede', 'fecha' => '2026-09-25',
            'categoria' => 'actividad', 'institucional' => false,
            'grupo_tokens' => [$groupToken], 'materia_token' => $subjectToken,
        ])->assertForbidden();
        $this->postJson("{$api}?opaque=1", [
            'titulo' => 'Circular docente', 'descripcion' => 'No procede', 'fecha' => '2026-09-25',
            'categoria' => 'actividad', 'institucional' => true, 'grupo_tokens' => [],
        ])->assertForbidden();
    }

    public function test_event_opaque_create_rejects_a_teacher_without_the_selected_group_assignment(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $unassigned = User::create(['name' => 'Docente sin grupo', 'email' => 'event-unassigned@test.test',
            'password' => 'Password12345', 'role' => 'docente', 'status' => 'active']);
        $unassigned->assignRole('docente');
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($unassigned->createToken('web')->plainTextToken);
        $this->postJson('http://localhost/api/eventos?opaque=1', [
            'titulo' => 'Evento no asignado', 'descripcion' => 'No procede', 'fecha' => '2026-09-25',
            'categoria' => 'actividad', 'institucional' => false,
            'grupo_tokens' => [OpaqueUrlToken::for('grupo', $this->group->id)],
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
        ])->assertForbidden();
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
        Role::findOrCreate('estudiante', 'web')
            ->givePermissionTo(Permission::findOrCreate('notas.ver_propias', 'web'));
        $student = User::create(['name' => 'Estudiante', 'email' => 'student@test.test', 'password' => 'Student123456', 'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id, 'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $component = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id, 'periodo_id' => $period->id, 'nombre' => 'Talleres', 'modo' => 'SIMPLE_AVERAGE']);
        $activity = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Taller 1', 'fecha' => '2026-02-05']);

        return [$period, $enrollment, $activity];
    }

    public function test_bulletin_catalog_marks_only_calculated_failing_results_for_recovery(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $failed, $firstActivity] = $this->gradeFixture();
        $secondActivity = ActividadEvaluacion::create(['componente_id' => $firstActivity->componente_id,
            'nombre' => 'Taller 2', 'fecha' => '2026-02-06']);
        $newEnrollment = function (string $name): Matricula {
            $student = User::create(['name' => $name, 'email' => strtolower($name).'@test.invalid',
                'password' => 'Student123456', 'role' => 'estudiante', 'status' => 'active']);
            $student->assignRole('estudiante');

            return Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        };
        $passing = $newEnrollment('Aprobado');
        $mixed = $newEnrollment('Promedio aprobado');
        $pending = $newEnrollment('Pendiente');
        $rows = [];
        foreach ([[$failed, '2', '2'], [$passing, '4', '4'], [$mixed, '2', '5']] as [$enrollment, $first, $second]) {
            foreach ([[$firstActivity, $first], [$secondActivity, $second]] as [$activity, $value]) {
                $rows[] = ['actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
                    'valor' => $value, 'version' => 0];
            }
        }
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, $rows);

        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $url = 'http://localhost/api/evaluacion/catalogo?opaque=1&vista=boletines'
            .'&ano_lectivo_token='.OpaqueUrlToken::for('ano-lectivo', $this->year->id)
            .'&matriculas_per_page=2';
        $pageOne = collect($this->getJson($url)->assertOk()->json('data.matriculas'))
            ->keyBy('estudiante.name');
        $this->assertFalse($pageOne['Pendiente']['tiene_resultados_reprobados']);
        $this->assertFalse($pageOne['Promedio aprobado']['tiene_resultados_reprobados']);
        $pageTwo = collect($this->getJson($url.'&matriculas_page=2')->assertOk()->json('data.matriculas'))
            ->keyBy('estudiante.name');
        $this->assertFalse($pageTwo['Aprobado']['tiene_resultados_reprobados']);
        $this->assertTrue($pageTwo['Estudiante']['tiene_resultados_reprobados']);
        $this->assertArrayNotHasKey('id', $pageTwo['Estudiante']);

        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        $recovery = app(AcademicRecoveryService::class)->create($this->rector, $failed, $this->assignment, $period);
        app(AcademicRecoveryService::class)->record($this->rector, $recovery, '4', null, 1, 'Nivelación aprobada');
        $afterRecovery = collect($this->getJson($url.'&matriculas_page=2')->assertOk()->json('data.matriculas'))
            ->keyBy('estudiante.name');
        $this->assertFalse($afterRecovery['Estudiante']['tiene_resultados_reprobados']);
        $this->getJson('http://localhost/api/evaluacion/boletines/'.OpaqueUrlToken::for('matricula', $failed->id).'?opaque=1')
            ->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'recuperacion');
    }

    public function test_promotion_needs_complete_grades_and_explicit_current_approval_to_close_year(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 1]);
        $period->update(['fecha_fin' => '2026-12-31']);
        $target = Grado::create(['ano_lectivo_id' => $this->year->id,
            'nivel_id' => $this->group->grado->nivel_id, 'nombre' => 'Segundo',
            'codigo' => '02', 'estado' => 'activo']);
        $service = app(AcademicPromotionService::class);
        $policy = $service->savePolicy($this->rector, $this->year, 0, [], null, 0);
        $this->assertSame(1, $policy->version);
        $this->assertSame('pendiente', $service->proposal($this->year->fresh(), $enrollment)['estado']);

        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '4.0', 'version' => 0, 'motivo' => 'Evaluación registrada',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $proposal = $service->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('promovido', $proposal['resultado']);
        $this->assertCount(0, $proposal['reprobadas']);
        $review = app(\App\Services\AcademicYearReviewService::class)->review($this->year->fresh());
        $this->assertContains('promotion_pending', $review['bloqueos']);

        try {
            $service->approve($this->teacher, $this->year, $enrollment, $proposal['huella'],
                'promovido', $target, 'Resultados completos', 0);
            $this->fail('Un docente no debe aprobar la promoción.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $decision = $service->approve($this->rector, $this->year, $enrollment, $proposal['huella'],
            'promovido', $target, 'Resultados anuales revisados', 0);
        $this->assertSame($target->id, $decision->grado_destino_id);
        $this->assertTrue($service->isCurrent($this->year->fresh(), $enrollment, $decision));
        $review = app(\App\Services\AcademicYearReviewService::class)->review($this->year->fresh());
        $this->assertTrue($review['puede_cerrar']);
        $this->assertSame(1, $review['promociones_aprobadas']);

        $request = Request::create('/', 'POST', ['confirmar_promociones' => true]);
        $request->setUserResolver(fn () => $this->rector);
        app(AnoLectivoController::class)->cerrar($request, (string) $this->year->id);
        $this->assertSame(AnoLectivo::ESTADO_CERRADO, $this->year->fresh()->estado);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'promocion_academica',
            'recurso_id' => (string) $decision->id, 'accion' => 'CREATE']);
    }

    public function test_promotion_rejects_stale_proposal_and_policy_change_invalidates_decision(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 1]);
        $period->update(['fecha_fin' => '2026-12-31']);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2.0', 'version' => 0, 'motivo' => 'Evaluación registrada',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $service = app(AcademicPromotionService::class);
        $service->savePolicy($this->rector, $this->year, 0, [], null, 0);
        $first = $service->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('reprobado', $first['resultado']);
        $service->savePolicy($this->rector, $this->year, 1, [], null, 1);
        try {
            $service->approve($this->rector, $this->year, $enrollment, $first['huella'],
                'reprobado', null, 'Revisión anual', 0);
            $this->fail('La propuesta con política antigua no debe aprobarse.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $second = $service->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('promovido', $second['resultado']);
        $target = Grado::create(['ano_lectivo_id' => $this->year->id,
            'nivel_id' => $this->group->grado->nivel_id, 'nombre' => 'Segundo',
            'codigo' => '02', 'estado' => 'activo']);
        $decision = $service->approve($this->rector, $this->year, $enrollment, $second['huella'],
            'promovido', $target, 'Aplicación de política anual', 0);
        $service->savePolicy($this->rector, $this->year, 0, [], null, 2);
        $this->assertFalse($service->isCurrent($this->year->fresh(), $enrollment, $decision));
        $review = app(\App\Services\AcademicYearReviewService::class)->review($this->year->fresh());
        $this->assertContains('promotion_outdated', $review['bloqueos']);
    }

    public function test_promotion_api_scopes_year_and_uses_only_public_selectors(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [, $enrollment] = $this->gradeFixture();
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $url = "http://localhost/api/anos-lectivos/{$yearToken}/promociones";
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);

        $this->putJson($url.'/politica?opaque=1', [
            'max_reprobadas' => 0, 'materias_obligatorias' => [$subjectToken],
            'promedio_minimo' => null, 'version' => 0,
        ])->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson($url.'?opaque=1')->assertOk()
            ->assertJsonPath('data.0.matricula_token', $enrollmentToken)
            ->assertJsonPath('politica.materias_obligatorias.0', $subjectToken)
            ->assertJsonMissingPath('data.0.matricula_id')
            ->assertJsonMissingPath('politica.ano_lectivo_id');
        $this->getJson("http://localhost/api/anos-lectivos/{$enrollmentToken}/promociones?opaque=1")
            ->assertNotFound();
        $this->putJson($url.'/politica?opaque=1', [
            'max_reprobadas' => 0, 'materias_obligatorias' => [$enrollmentToken],
            'promedio_minimo' => null, 'version' => 1,
        ])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->getJson($url.'?opaque=1')->assertForbidden();
        $this->putJson($url.'/politica?opaque=1', [
            'max_reprobadas' => 0, 'materias_obligatorias' => [],
            'promedio_minimo' => null, 'version' => 1,
        ])->assertForbidden();
    }

    public function test_general_average_scope_does_not_ignore_the_siee_passing_threshold(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 1]);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2.0', 'version' => 0, 'motivo' => 'Evaluación registrada',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $service = app(AcademicPromotionService::class);
        $service->savePolicy($this->rector, $this->year, 1, [], null, 0);
        $this->assertSame('promovido', $service->proposal($this->year->fresh(), $enrollment)['resultado']);
        MetodoAprobacion::where('ano_lectivo_id', $this->year->id)
            ->update(['ambito' => MetodoAprobacion::AMBITO_PROMEDIO_GENERAL]);
        $proposal = $service->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('promedio_general', $proposal['ambito']);
        $this->assertFalse($proposal['promedio_cumple']);
        $this->assertSame('reprobado', $proposal['resultado']);
    }

    public function test_general_average_promotion_agrees_with_the_published_passing_grade(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 1]);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2.974', 'version' => 0, 'motivo' => 'Evaluación registrada',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        MetodoAprobacion::where('ano_lectivo_id', $this->year->id)
            ->update(['ambito' => MetodoAprobacion::AMBITO_PROMEDIO_GENERAL]);
        $service = app(AcademicPromotionService::class);
        $service->savePolicy($this->rector, $this->year, 0, [], '3.0', 0);

        $proposal = $service->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('3.0', $proposal['promedio']);
        $this->assertTrue($proposal['promedio_cumple']);
        $this->assertSame('promovido', $proposal['resultado']);
    }

    public function test_period_recovery_keeps_original_grade_and_can_be_cancelled_or_reopened(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2.0', 'version' => 0, 'motivo' => 'Evaluación del período',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $service = app(AcademicRecoveryService::class);
        $candidate = $service->create($this->rector, $enrollment, $this->assignment, $period,
            'Refuerzo de los temas pendientes');
        $this->assertSame('pendiente', $candidate->estado);
        $this->assertSame('2', $candidate->valor_original_exacto);
        try {
            $service->create($this->rector, $enrollment, $this->assignment, $period);
            $this->fail('No se debe duplicar una nivelación.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $resolved = $service->record($this->teacher, $candidate, '4.0', null, 1, 'Superó el plan de refuerzo');
        $this->assertSame('aprobada', $resolved->estado);
        $report = app(GradebookService::class)->report($enrollment);
        $result = $report['asignaturas'][0]['periodos'][0];
        $this->assertSame('4', $result['exact_value']);
        $this->assertSame('2', $result['resultado_original']['exact_value']);
        $this->assertSame('recuperacion', $result['origen']);
        $this->assertSame('2', Calificacion::firstOrFail()->valor);
        try {
            $service->record($this->teacher, $candidate, '5', null, 1, 'Intento obsoleto');
            $this->fail('El versionado debe impedir sobrescrituras.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $cancelled = $service->cancel($this->rector, $resolved, 2, 'Se registró en el estudiante equivocado');
        $this->assertSame('anulada', $cancelled->estado);
        $this->assertSame('2', app(GradebookService::class)->report($enrollment)['asignaturas'][0]['periodos'][0]['exact_value']);
        $reopened = $service->create($this->rector, $enrollment, $this->assignment, $period);
        $this->assertSame($candidate->id, $reopened->id);
        $this->assertSame(4, $reopened->version);
        $this->assertDatabaseCount('recuperaciones_academicas', 1);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'recuperacion_academica',
            'recurso_id' => (string) $candidate->id, 'accion' => 'DELETE']);
    }

    public function test_annual_habilitation_caps_effective_grade_without_losing_recovery_score(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        $this->year->update(['num_periodos' => 1,
            'siee' => [...$this->year->siee, 'recuperacion' => 'MAX_PASSING_GRADE']]);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2.5', 'version' => 0, 'motivo' => 'Evaluación del período',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $service = app(AcademicRecoveryService::class);
        $candidate = $service->create($this->rector, $enrollment, $this->assignment, null);
        $resolved = $service->record($this->rector, $candidate, '4.5', null, 1, 'Habilitación completada');
        $this->assertSame('habilitacion', $resolved->tipo);
        $this->assertSame('4.50000000', $resolved->nota_recuperacion);
        $this->assertSame('3', $resolved->valor_efectivo_exacto);
        $report = app(GradebookService::class)->report($enrollment);
        $this->assertSame('5/2', $report['asignaturas'][0]['periodos'][0]['exact_value']);
        $this->assertSame('3', $report['asignaturas'][0]['anual']['exact_value']);
        $this->assertSame('5/2', $report['asignaturas'][0]['anual']['resultado_original']['exact_value']);
    }

    public function test_average_and_manual_recovery_policies_validate_scale_and_keep_original(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2', 'version' => 0, 'motivo' => 'Evaluación del período',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $this->year->update(['siee' => [...$this->year->siee, 'recuperacion' => 'AVERAGE']]);
        $service = app(AcademicRecoveryService::class);
        $average = $service->create($this->rector, $enrollment, $this->assignment, $period);
        $average = $service->record($this->teacher, $average, '4', null, 1, 'Nivelación promediada');
        $this->assertSame('3', $average->valor_efectivo_exacto);
        $service->cancel($this->rector, $average, 2, 'Se requiere calificación manual según SIEE');
        $this->year->update(['siee' => [...$this->year->siee, 'recuperacion' => 'MANUAL']]);
        $manual = $service->create($this->rector, $enrollment, $this->assignment, $period);
        $this->assertSame(4, $manual->version);
        try {
            $service->record($this->teacher, $manual, '4', null, 4, 'Sin definitiva manual');
            $this->fail('La política manual exige definitiva separada.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        try {
            $service->record($this->teacher, $manual, '6', '3.5', 4, 'Fuera de escala');
            $this->fail('No se debe aceptar una nota fuera de escala.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $manual = $service->record($this->teacher, $manual, '4', '3.5', 4, 'Definitiva validada');
        $this->assertSame('7/2', $manual->valor_efectivo_exacto);
        $this->assertSame('3.5', app(GradebookService::class)->report($enrollment)['asignaturas'][0]['periodos'][0]['display_value']);
        $this->assertSame('2', Calificacion::firstOrFail()->valor);
    }

    public function test_recovery_api_uses_public_selectors_and_scopes_students_to_their_own_records(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2', 'version' => 0, 'motivo' => 'Evaluación del período',
        ]]);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $base = 'http://localhost/api/evaluacion/recuperaciones';
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);

        $candidates = $this->getJson("{$base}?opaque=1&matricula_token={$enrollmentToken}")
            ->assertOk()->assertJsonPath('candidatos.0.asignacion_token', $assignmentToken)
            ->json();
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($candidates));
        $created = $this->postJson("{$base}?opaque=1", [
            'matricula_token' => $enrollmentToken, 'asignacion_token' => $assignmentToken,
            'periodo_token' => $periodToken,
        ])->assertCreated()->json('data');
        $this->assertSame('pendiente', $created['estado']);
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($created));
        $this->postJson("{$base}?opaque=1", [
            'matricula_token' => $enrollmentToken, 'asignacion_token' => $assignmentToken,
            'periodo_token' => $periodToken,
        ])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->putJson("{$base}/{$created['url_token']}?opaque=1", [
            'nota_recuperacion' => '4', 'version' => 1,
            'motivo' => 'Cumplió el refuerzo',
        ])->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->app['auth']->forgetGuards();
        $this->withToken($enrollment->estudiante->createToken('web')->plainTextToken);
        $visible = $this->getJson("{$base}?opaque=1&matricula_token={$enrollmentToken}")
            ->assertOk()->assertJsonPath('data.0.estado', 'aprobada')->json();
        $this->assertSame([], $visible['candidatos']);
        $this->assertFalse($visible['can_manage']);
        $this->postJson("{$base}?opaque=1", [
            'matricula_token' => $enrollmentToken, 'asignacion_token' => $assignmentToken,
            'periodo_token' => $periodToken,
        ])->assertForbidden();
    }

    public function test_open_period_shows_provisional_recovery_that_disappears_when_grade_passes(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('notas.gestionar_nivelaciones', 'web'));
        $book = app(GradebookService::class);
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2', 'version' => 0, 'motivo' => 'Evaluación del período',
        ]]);
        $base = 'http://localhost/api/evaluacion/recuperaciones';
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);

        $this->getJson("{$base}?opaque=1&matricula_token={$enrollmentToken}")
            ->assertOk()->assertJsonPath('candidatos.0.asignacion_token', $assignmentToken)
            ->assertJsonPath('candidatos.0.puede_abrir', false);
        $this->postJson("{$base}?opaque=1", [
            'matricula_token' => $enrollmentToken, 'asignacion_token' => $assignmentToken,
            'periodo_token' => $periodToken,
        ])->assertStatus(422);

        $book->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '3', 'version' => 1, 'motivo' => 'Corrección de la evaluación',
        ]]);
        $this->getJson("{$base}?opaque=1&matricula_token={$enrollmentToken}")
            ->assertOk()->assertJsonPath('candidatos', []);
    }

    public function test_gradebook_reads_grades_once_per_page_and_preserves_calculated_results(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => '4.5', 'version' => 0, 'motivo' => 'Evaluación inicial',
        ]]);
        for ($i = 0; $i < 10; $i++) {
            $student = User::create(['name' => 'Estudiante '.$i, 'email' => 'batch'.$i.'@test.test',
                'password' => 'Student123456', 'role' => 'estudiante', 'status' => 'active']);
            Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }
        $expected = app(GradebookService::class)->subjectResult($this->assignment, $enrollment, $period,
            app(SieeConfiguration::class)->resolve($this->year->fresh()));
        $request = Request::create('/evaluacion/planillas', 'GET');
        $request->setUserResolver(fn () => $this->teacher);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(EvaluacionController::class)->planilla($request, $this->assignment->id, $period->id)->getData(true)['data'];
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(11, $result['resultados']);
        $gradedIndex = collect($result['matriculas'])->search(fn ($row) => $row['id'] === $enrollment->id);
        $this->assertNotFalse($gradedIndex);
        $this->assertSame($expected['exact_value'], $result['resultados'][$gradedIndex]['exact_value']);
        $this->assertSame('pendiente', collect($result['resultados'])->except([$gradedIndex])->first()['estado']);
        foreach (['calificaciones', 'componentes_evaluacion', 'actividades_evaluacion'] as $table) {
            $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q['query'], 'from "'.$table.'"'))->count(), $table);
        }
    }

    public function test_evaluation_opaque_contract_covers_catalog_sheet_writes_and_report(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/evaluacion';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $activityToken = OpaqueUrlToken::for('actividad-evaluacion', $activity->id);

        $catalog = $this->getJson("{$api}/catalogo?opaque=1&ano_lectivo_token={$yearToken}")
            ->assertOk()->assertJsonPath('data.asignaciones.0.url_token', $assignmentToken)
            ->assertJsonPath('data.matriculas.0.url_token', $enrollmentToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($catalog));

        $sheet = $this->getJson("{$api}/planillas/{$assignmentToken}/{$periodToken}?opaque=1")
            ->assertOk()->assertJsonPath('data.componentes.0.actividades.0.url_token', $activityToken)
            ->assertJsonPath('data.resultados.0.matricula_token', $enrollmentToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($sheet));

        $this->postJson("{$api}/componentes?opaque=1", [
            'asignacion_token' => $assignmentToken, 'periodo_token' => $periodToken,
            'nombre' => 'Exámenes', 'modo' => 'SIMPLE_AVERAGE',
        ])->assertCreated()->assertJsonMissingPath('data.id');
        $componentToken = OpaqueUrlToken::for('componente-evaluacion', $activity->componente_id);
        $this->postJson("{$api}/actividades?opaque=1", [
            'componente_token' => $componentToken, 'nombre' => 'Taller 2',
            'fecha' => '2026-02-10',
        ])->assertCreated()->assertJsonMissingPath('data.id');
        $saved = $this->putJson("{$api}/planillas/{$assignmentToken}/{$periodToken}?opaque=1", [
            'notas' => [['actividad_token' => $activityToken, 'matricula_token' => $enrollmentToken,
                'valor' => '4.5', 'version' => 0, 'motivo' => 'Evaluación inicial']],
        ])->assertOk()->assertJsonPath('data.calificaciones.0.matricula_token', $enrollmentToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($saved));
        $report = $this->getJson("{$api}/boletines/{$enrollmentToken}?opaque=1")
            ->assertOk()->assertJsonPath('data.estudiante.url_token', OpaqueUrlToken::for('usuario', $enrollment->estudiante_id))
            ->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($report));
        $this->assertDoesNotMatchRegularExpression('/(?:actividad|componente|materia|periodo):[0-9]+"/', json_encode($report));
    }

    public function test_evaluation_opaque_rejects_numeric_and_foreign_selectors(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/evaluacion';
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $activityToken = OpaqueUrlToken::for('actividad-evaluacion', $activity->id);

        $this->getJson("{$api}/planillas/{$this->assignment->id}/{$periodToken}?opaque=1")->assertNotFound();
        $this->getJson("{$api}/boletines/{$enrollment->id}?opaque=1")->assertNotFound();
        $this->postJson("{$api}/matriculas?opaque=1", [
            'grupo_id' => $this->group->id, 'estudiante_id' => $enrollment->estudiante_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('grupo_id');
        $this->putJson("{$api}/planillas/{$assignmentToken}/{$periodToken}?opaque=1", [
            'notas' => [['actividad_id' => $activity->id, 'matricula_token' => $enrollmentToken,
                'valor' => '4', 'version' => 0, 'motivo' => 'Prueba inválida']],
        ])->assertUnprocessable();
        $this->postJson("{$api}/componentes?opaque=1", [
            'asignacion_token' => $periodToken, 'periodo_token' => $periodToken,
            'nombre' => 'Forjado', 'modo' => 'SIMPLE_AVERAGE',
        ])->assertUnprocessable();
        $this->getJson("{$api}/catalogo?opaque=1&grupo_id={$this->group->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('grupo_id');

        $otherSchool = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => 'other-evaluation-opaque', 'name' => 'Otro colegio', 'slug' => 'other-evaluation-opaque',
            'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active',
        ]));
        tenancy()->initialize($otherSchool);
        $foreignEnrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        tenancy()->initialize($this->school);
        $this->getJson("{$api}/boletines/{$foreignEnrollmentToken}?opaque=1")->assertNotFound();
        $this->putJson("{$api}/planillas/{$assignmentToken}/{$periodToken}?opaque=1", [
            'notas' => [['actividad_token' => $activityToken, 'matricula_token' => $foreignEnrollmentToken,
                'valor' => '4', 'version' => 0, 'motivo' => 'Prueba inválida']],
        ])->assertUnprocessable();
    }

    public function test_schedule_opaque_lists_and_writes_keep_private_keys_server_side(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('web')->plainTextToken);
        $api = 'http://localhost/api';
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $groupToken = OpaqueUrlToken::for('grupo', $this->group->id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        $teacherToken = OpaqueUrlToken::for('usuario', $this->teacher->id);
        $blockToken = OpaqueUrlToken::for('bloque-horario', $this->block->id);

        $list = $this->getJson("{$api}/horarios?opaque=1&ano_lectivo_token={$yearToken}&grupo_token={$groupToken}&vista=horarios")
            ->assertOk()->assertJsonPath('data.asignaciones.0.url_token', $assignmentToken)
            ->assertJsonPath('data.grupos.0.url_token', $groupToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($list));
        $options = $this->getJson("{$api}/catalogos-academicos?opaque=1&tipo=bloques&ano_lectivo_token={$yearToken}")
            ->assertOk()->assertJsonPath('data.0.url_token', $blockToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($options));
        $space = EspacioFisico::create(['ano_lectivo_id' => $this->year->id, 'sede_id' => $this->group->sede_id,
            'nombre' => 'Aula 101', 'tipo' => 'aula', 'estado' => EspacioFisico::ESTADO_DISPONIBLE]);
        $spaceToken = OpaqueUrlToken::for('espacio-fisico', $space->id);
        $spaceOptions = $this->getJson("{$api}/catalogos-academicos?opaque=1&tipo=espacios&ano_lectivo_token={$yearToken}")
            ->assertOk()->assertJsonPath('data.0.url_token', $spaceToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($spaceOptions));

        $this->postJson("{$api}/asignaciones?opaque=1", [
            'ano_lectivo_token' => $yearToken, 'grupo_token' => $groupToken,
            'materia_token' => $subjectToken, 'docente_token' => $teacherToken,
        ])->assertOk()->assertJsonPath('data.url_token', $assignmentToken)->assertJsonMissingPath('data.id');
        $created = $this->postJson("{$api}/horarios?opaque=1", [
            'asignacion_token' => $assignmentToken, 'grupo_token' => $groupToken,
            'materia_token' => $subjectToken, 'docente_token' => $teacherToken,
            'bloque_horario_token' => $blockToken, 'dia' => 'lunes',
        ])->assertCreated()->assertJsonPath('data.grupo_token', $groupToken)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($created));
        $this->getJson("{$api}/horarios?opaque=1&ano_lectivo_token={$yearToken}&grupo_token={$groupToken}&vista=horarios")
            ->assertOk()->assertJsonPath('data.sesiones.0.url_token', $created['url_token']);
        $this->getJson("{$api}/horarios?opaque=1&grupo_id={$this->group->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('grupo_id');
        $this->putJson("{$api}/horarios/".SesionHorario::firstOrFail()->id.'?opaque=1', [
            'dia' => 'martes', 'grupo_token' => $groupToken, 'materia_token' => $subjectToken,
            'bloque_horario_token' => $blockToken,
        ])->assertNotFound();
    }

    public function test_opaque_people_options_limit_students_to_the_assigned_teacher(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment] = $this->gradeFixture();
        $other = User::create(['name' => 'Estudiante ajeno', 'email' => 'other-student@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $other->assignRole('estudiante');
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->teacher->createToken('web')->plainTextToken);
        $api = 'http://localhost/api/catalogos-academicos?opaque=1';

        $students = $this->getJson($api.'&tipo=estudiantes')->assertOk()->json('data');
        $this->assertSame([OpaqueUrlToken::for('usuario', $enrollment->estudiante_id)],
            array_column($students, 'url_token'));
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($students));
        $teachers = $this->getJson($api.'&tipo=docentes')->assertOk()->json('data');
        $this->assertSame([OpaqueUrlToken::for('usuario', $this->teacher->id)],
            array_column($teachers, 'url_token'));
        $this->getJson($api.'&tipo=estudiantes&selected_token='.OpaqueUrlToken::for('usuario', $other->id))
            ->assertUnprocessable()->assertJsonValidationErrors('selected_token');
    }

    public function test_grade_updates_are_exact_audited_and_optimistically_locked(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $book = new GradebookService;
        $row = ['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => '2.99999999', 'version' => 0, 'motivo' => 'Registro inicial'];
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [$row]);
        $result = $book->subjectResult($this->assignment, $enrollment, $period, app(SieeConfiguration::class)->resolve($this->year));
        $this->assertSame('3.0', $result['display_value']);
        $this->assertTrue($result['aprobado']); // La aprobación coincide con la nota publicada: 3.0.
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
        $this->assertStringContainsString('sin asignación para este grupo', $report['advertencias'][0]);
        $this->assertStringContainsString('no tiene asignación para este grupo', $report['asignaturas'][1]['anual']['motivo']);
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

    public function test_gradebook_and_enrollment_actions_require_their_permissions_even_for_a_rector(): void
    {
        $rectorRole = Role::findByName('rector', 'web');
        $rectorRole->revokePermissionTo('notas.editar_no_dicta');
        $rectorRole->revokePermissionTo('academico.matriculas.gestionar');
        $this->rector = $this->rector->fresh();
        $this->assertFalse($this->rector->can('notas.editar_no_dicta'));
        $this->assertFalse($this->rector->can('academico.matriculas.gestionar'));

        $book = new GradebookService;
        $book->authorizeAssignment($this->rector, $this->assignment);
        try {
            $book->authorizeAssignment($this->rector, $this->assignment, write: true);
            $this->fail('Ver el consolidado no permite modificar notas ajenas.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $request = Request::create('/api/evaluacion/matriculas', 'POST', [
            'grupo_id' => $this->group->id, 'estudiante_id' => $this->teacher->id,
        ]);
        $request->setUserResolver(fn () => $this->rector);
        try {
            app(EvaluacionController::class)->matricular($request);
            $this->fail('El rol de rector no debe sustituir el permiso de matrícula revocado.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('matriculas', 0);
    }

    public function test_assigned_teacher_cannot_access_another_teachers_gradebook_or_write_without_permission(): void
    {
        $other = User::create(['name' => 'Otra docente', 'email' => 'other-gradebook@test.test',
            'password' => 'Password12345', 'role' => 'docente', 'status' => 'active']);
        $other->assignRole('docente');
        $book = new GradebookService;
        foreach ([false, true] as $write) {
            try {
                $book->authorizeAssignment($other, $this->assignment, write: $write);
                $this->fail('Un docente no debe acceder a la asignación de otro.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        Role::findByName('docente', 'web')->revokePermissionTo('notas.registrar_materia_asignada');
        $this->teacher = $this->teacher->fresh();
        try {
            $book->authorizeAssignment($this->teacher, $this->assignment, write: true);
            $this->fail('La asignación no debe sustituir un permiso revocado.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
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

    public function test_fourteen_grades_arrive_complete_even_if_a_five_row_page_was_requested(): void
    {
        for ($i = 2; $i <= 14; $i++) {
            Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $this->group->grado->nivel_id,
                'nombre' => 'Grado '.$i, 'codigo' => sprintf('%02d', $i), 'estado' => 'activo']);
        }

        $small = app(GradoController::class)->index(Request::create('/api/grados?ano_lectivo_id='.$this->year->id
            .'&page=3&per_page=5'))->getData(true);
        $this->assertCount(14, $small['data']);
        $this->assertSame(14, $small['meta']['total']);
        $this->assertSame(20, $small['meta']['per_page']);
        $this->assertSame(1, $small['meta']['current_page']);
        $this->assertSame(1, $small['meta']['last_page']);

        for ($i = 15; $i <= 20; $i++) {
            Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $this->group->grado->nivel_id,
                'nombre' => 'Grado '.$i, 'codigo' => sprintf('%02d', $i), 'estado' => 'activo']);
        }
        $threshold = app(GradoController::class)->index(Request::create('/api/grados?ano_lectivo_id='.$this->year->id
            .'&per_page=5'))->getData(true);
        $this->assertCount(20, $threshold['data']);
        $this->assertSame(1, $threshold['meta']['last_page']);

        Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $this->group->grado->nivel_id,
            'nombre' => 'Grado 21', 'codigo' => '21', 'estado' => 'activo']);
        $large = app(GradoController::class)->index(Request::create('/api/grados?ano_lectivo_id='.$this->year->id
            .'&page=2&per_page=5'))->getData(true);
        $this->assertCount(5, $large['data']);
        $this->assertSame(21, $large['meta']['total']);
        $this->assertSame(2, $large['meta']['current_page']);
        $this->assertSame(5, $large['meta']['per_page']);
    }

    public function test_academic_lists_page_in_sql_and_filter_before_counting(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            Area::create(['ano_lectivo_id' => $this->year->id, 'nombre' => sprintf('Área %02d', $i), 'estado' => 'activo']);
        }
        $controller = app(\App\Http\Controllers\Api\Academico\PlanEstudiosController::class);
        $first = $controller->areas(Request::create('/api/plan-estudios/areas?ano_lectivo_id='.$this->year->id))->getData(true);
        $second = $controller->areas(Request::create('/api/plan-estudios/areas?ano_lectivo_id='.$this->year->id.'&page=2'))->getData(true);
        $filtered = $controller->areas(Request::create('/api/plan-estudios/areas?ano_lectivo_id='.$this->year->id.'&search=%C3%81rea%202&per_page=5'))->getData(true);
        $all = $controller->areas(Request::create('/api/plan-estudios/areas?ano_lectivo_id='.$this->year->id.'&per_page=1000'))->getData(true);

        $this->assertCount(20, $first['data']);
        $this->assertCount(3, $second['data']);
        $this->assertSame(23, $first['meta']['total']);
        $this->assertSame(20, $first['meta']['per_page']);
        $this->assertSame(2, $first['meta']['last_page']);
        $this->assertSame(21, $second['meta']['from']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        $this->assertSame(3, $filtered['meta']['total']);
        $this->assertCount(3, $filtered['data']);
        $this->assertSame(20, $filtered['meta']['per_page']);
        $this->assertCount(23, $all['data']);
        $this->assertSame(1000, $all['meta']['per_page']);
    }

    public function test_assignments_and_evaluation_catalog_have_independent_pagination_and_direct_link_lookup(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        Role::findOrCreate('estudiante', 'web');
        for ($i = 1; $i <= 21; $i++) {
            $subject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => sprintf('Materia %02d', $i),
                'intensidad_horaria' => 1, 'estado' => 'activo']);
            AsignacionDocente::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $this->group->id,
                'materia_id' => $subject->id, 'docente_id' => $this->teacher->id]);
        }
        $request = Request::create('/api/horarios?ano_lectivo_id='.$this->year->id.'&page=2&per_page=5');
        $request->setUserResolver(fn () => $this->rector);
        $schedule = app(HorarioController::class)->index($request)->getData(true)['data'];
        $this->assertCount(5, $schedule['asignaciones']);
        $this->assertSame(22, $schedule['pagination']['asignaciones']['total']);
        $this->assertSame(2, $schedule['pagination']['asignaciones']['current_page']);

        $token = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $request = Request::create('/api/evaluacion/catalogo?ano_lectivo_id='.$this->year->id.'&asignaciones_page=2&asignaciones_per_page=5&asignacion_token='.$token);
        $request->setUserResolver(fn () => $this->rector);
        $catalog = app(EvaluacionController::class)->catalogo($request)->getData(true)['data'];
        $this->assertCount(5, $catalog['asignaciones']);
        $this->assertSame(22, $catalog['pagination']['asignaciones']['total']);
        $this->assertSame(2, $catalog['pagination']['asignaciones']['current_page']);
        $this->assertSame($this->assignment->id, $catalog['selected_asignacion']['id']);
        $this->assertSame(1, $catalog['pagination']['matriculas']['current_page']);
    }

    public function test_teacher_evaluation_filters_include_only_their_assigned_groups_and_subjects(): void
    {
        Role::findOrCreate('estudiante', 'web');
        $otherSubject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Ajedrez',
            'intensidad_horaria' => 1, 'estado' => 'activo']);
        $request = Request::create('/api/evaluacion/catalogo?ano_lectivo_id='.$this->year->id);
        $request->setUserResolver(fn () => $this->teacher);
        $catalog = app(EvaluacionController::class)->catalogo($request)->getData(true)['data'];

        $this->assertSame([$this->group->id], array_column($catalog['grupos'], 'id'));
        $this->assertSame([$this->subject->id], array_column($catalog['materias'], 'id'));
        $this->assertNotContains($otherSubject->id, array_column($catalog['materias'], 'id'));
    }

    public function test_large_subject_catalogs_are_bounded_and_remote_options_preserve_selection(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $last = null;
        for ($i = 1; $i <= 60; $i++) {
            $last = Materia::create(['ano_lectivo_id' => $this->year->id,
                'nombre' => sprintf('Electiva %02d', $i), 'intensidad_horaria' => 1, 'estado' => 'activo']);
        }
        $request = Request::create('/api/horarios?ano_lectivo_id='.$this->year->id);
        $request->setUserResolver(fn () => $this->rector);
        $schedule = app(HorarioController::class)->index($request)->getData(true)['data'];
        $this->assertCount(50, $schedule['materias']);
        $this->assertSame(61, $schedule['counts']['materias']);

        $request = Request::create('/api/catalogos-academicos?tipo=materias&ano_lectivo_id='.$this->year->id.'&selected_id='.$last->id);
        $request->setUserResolver(fn () => $this->rector);
        $options = app(\App\Http\Controllers\Api\Academico\AcademicOptionsController::class)($request)->getData(true);
        $this->assertSame(61, $options['meta']['total']);
        $this->assertSame(20, $options['meta']['per_page']);
        $this->assertCount(21, $options['data']);
        $this->assertContains($last->id, array_column($options['data'], 'id'));

        $request = Request::create('/api/catalogos-academicos?tipo=materias&ano_lectivo_id='.$this->year->id.'&search=Electiva%2060');
        $request->setUserResolver(fn () => $this->rector);
        $found = app(\App\Http\Controllers\Api\Academico\AcademicOptionsController::class)($request)->getData(true);
        $this->assertSame(1, $found['meta']['total']);
        $this->assertSame($last->id, $found['data'][0]['id']);
    }

    public function test_curriculum_subject_options_follow_the_grade_level_and_keep_general_subjects(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.plan_estudios.gestionar', 'web'));
        $primaryLevel = $this->group->grado->nivel_id;
        $secondaryLevel = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Secundaria',
            'nivel_educativo' => 'secundaria', 'estado' => 'activo']);
        $primary = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Lectura primaria',
            'nivel_id' => $primaryLevel, 'intensidad_horaria' => 2, 'estado' => 'activo']);
        $secondary = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Química secundaria',
            'nivel_id' => $secondaryLevel->id, 'intensidad_horaria' => 2, 'estado' => 'activo']);

        $request = Request::create('/api/catalogos-academicos?tipo=materias&ano_lectivo_id='.$this->year->id
            .'&compatible_nivel_id='.$primaryLevel.'&per_page=20');
        $request->setUserResolver(fn () => $this->rector);
        $options = app(\App\Http\Controllers\Api\Academico\AcademicOptionsController::class)($request)->getData(true)['data'];
        $ids = array_column($options, 'id');
        $this->assertContains($this->subject->id, $ids);
        $this->assertContains($primary->id, $ids);
        $this->assertNotContains($secondary->id, $ids);

        $badRequest = Request::create('/', 'PUT', ['grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $secondary->id), 'peso_area' => '20.5']);
        $badRequest->setUserResolver(fn () => $this->rector);
        try {
            app(SieeController::class)->curriculo($badRequest, $this->year->id);
            $this->fail('Una materia de secundaria no puede asignarse a primaria.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('materias_curriculares', ['grado_id' => $this->group->grado_id,
            'materia_id' => $secondary->id]);
    }

    public function test_curriculum_inherits_subject_area_and_updates_weight_without_trailing_zeroes(): void
    {
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'usar_areas' => true, 'modo_area' => 'WEIGHTED_AVERAGE']]);
        $controller = app(SieeController::class);
        $save = function (string $weight, ?int $areaId = null) use ($controller): array {
            $request = Request::create('/', 'PUT', [
                'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
                'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
                'area_token' => $areaId ? OpaqueUrlToken::for('area', $areaId) : null,
                'peso_area' => $weight,
            ]);
            $request->setUserResolver(fn () => $this->rector);

            return $controller->curriculo($request, $this->year->id)->getData(true)['data'];
        };

        $first = $save('20.5');
        $this->assertSame(OpaqueUrlToken::for('area', $this->subject->area_id), $first['curriculo'][0]['area_token']);
        $this->assertSame('20.5', $first['curriculo'][0]['peso_area']);
        $this->assertSame('20.5', DB::table('materias_curriculares')->value('peso_area'));

        $updated = $save('7.25', $this->subject->area_id);
        $this->assertSame('7.25', $updated['curriculo'][0]['peso_area']);
        $this->assertSame(1, DB::table('materias_curriculares')->count());

        $differentArea = Area::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Otra área', 'estado' => 'activo']);
        try {
            $save('8', $differentArea->id);
            $this->fail('El área del currículo debe coincidir con la materia.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('7.25', DB::table('materias_curriculares')->value('peso_area'));
    }

    public function test_curriculum_and_gradebook_page_related_rows_without_losing_totals(): void
    {
        DB::table('materias_curriculares')->where('ano_lectivo_id', $this->year->id)->delete();
        for ($i = 1; $i <= 21; $i++) {
            $subject = Materia::create(['ano_lectivo_id' => $this->year->id, 'nombre' => sprintf('Currículo %02d', $i),
                'intensidad_horaria' => 1, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $this->year->id,
                'grado_id' => $this->group->grado_id, 'materia_id' => $subject->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $defaultCurriculum = app(SieeController::class)->curriculoIndex(
            Request::create('/api/siee/'.$this->year->id.'/curriculo'), $this->year->id)->getData(true);
        $this->assertCount(20, $defaultCurriculum['data']);
        $this->assertSame(20, $defaultCurriculum['meta']['per_page']);
        $curriculum = app(SieeController::class)->curriculoIndex(
            Request::create('/api/siee/'.$this->year->id.'/curriculo?page=2&per_page=5'), $this->year->id)->getData(true);
        $this->assertCount(5, $curriculum['data']);
        $this->assertSame(21, $curriculum['meta']['total']);
        $this->assertSame(2, $curriculum['meta']['current_page']);

        [$period, $firstEnrollment, $activity] = $this->gradeFixture();
        for ($i = 1; $i <= 11; $i++) {
            $student = User::create(['name' => sprintf('Estudiante %02d', $i), 'email' => "student{$i}@test.test",
                'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
            $student->assignRole('estudiante');
            Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }
        (new GradebookService)->saveGrades($this->rector, $this->assignment, $period->id, [[
            'matricula_id' => $firstEnrollment->id, 'actividad_id' => $activity->id,
            'valor' => '4', 'version' => 0,
        ]]);
        $request = Request::create('/api/evaluacion/planillas/'.$this->assignment->id.'/'.$period->id.'?page=2');
        $request->setUserResolver(fn () => $this->rector);
        $sheet = app(EvaluacionController::class)->planilla($request, $this->assignment->id, $period->id)->getData(true)['data'];
        $this->assertCount(12, $sheet['matriculas']);
        $this->assertCount(12, $sheet['resultados']);
        $this->assertSame(12, $sheet['pagination']['matriculas']['total']);
        $this->assertSame(12, $sheet['pagination']['matriculas']['per_page']);
        $this->assertCount(1, $sheet['calificaciones']);
    }

    public function test_gradebook_and_bulletin_rosters_return_every_student_sorted_by_surname_without_pages(): void
    {
        [$period] = $this->gradeFixture();
        foreach (['Mariana Luisa Andrade Ortiz', 'Sofía Elena Andrade Anzuate'] as $index => $name) {
            $student = User::create(['name' => $name, 'email' => "andrade{$index}@test.test",
                'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
            Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }
        for ($i = 1; $i <= 51; $i++) {
            $student = User::create(['name' => sprintf('Alumno %02d Zeta %02d', $i, $i),
                'email' => "roster{$i}@test.test", 'password' => 'StudentPassword123',
                'role' => 'estudiante', 'status' => 'active']);
            Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }

        $request = Request::create('/api/evaluacion/planillas/'.$this->assignment->id.'/'.$period->id.'?page=2&per_page=5');
        $request->setUserResolver(fn () => $this->rector);
        $sheet = app(EvaluacionController::class)->planilla($request, $this->assignment->id, $period->id)->getData(true)['data'];
        $this->assertCount(54, $sheet['matriculas']);
        $this->assertCount(54, $sheet['resultados']);
        $this->assertSame(1, $sheet['pagination']['matriculas']['last_page']);
        $this->assertSame('Andrade Anzuate Sofía Elena', $sheet['matriculas'][0]['nombre_lista']);
        $this->assertSame('Andrade Ortiz Mariana Luisa', $sheet['matriculas'][1]['nombre_lista']);

        $request = Request::create('/api/evaluacion/catalogo?vista=boletines&ano_lectivo_id='.$this->year->id.'&matriculas_page=2&matriculas_per_page=5');
        $request->setUserResolver(fn () => $this->rector);
        $catalog = app(EvaluacionController::class)->catalogo($request)->getData(true)['data'];
        $this->assertCount(54, $catalog['matriculas']);
        $this->assertSame(54, $catalog['pagination']['matriculas']['total']);
        $this->assertSame(1, $catalog['pagination']['matriculas']['last_page']);
        $this->assertSame('Andrade Anzuate Sofía Elena', $catalog['matriculas'][0]['nombre_lista']);
        $this->assertSame('Andrade Ortiz Mariana Luisa', $catalog['matriculas'][1]['nombre_lista']);
        $namedEnrollment = Matricula::with(['estudiante', 'grupo.grado'])->findOrFail($catalog['matriculas'][0]['id']);
        $report = \App\Support\EvaluationOpaquePresenter::report(app(GradebookService::class)->report($namedEnrollment));
        $this->assertSame('Andrade Anzuate Sofía Elena', $report['estudiante']['nombre_lista']);
    }

    public function test_curriculum_weight_can_change_after_empty_period_closes_but_not_after_closed_grades(): void
    {
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS, 'usar_areas' => true, 'modo_area' => 'WEIGHTED_AVERAGE']]);
        $controller = app(SieeController::class);
        Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Período sin notas',
            'orden' => 2, 'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-08-31', 'estado' => 'cerrado']);

        $initial = $controller->show($this->year->id)->getData(true)['data'];
        $this->assertFalse($initial['editable']);
        $this->assertTrue($initial['curriculo_editable']);

        $request = Request::create('/', 'PUT', ['grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id), 'peso_area' => '20.5']);
        $request->setUserResolver(fn () => $this->rector);
        $saved = $controller->curriculo($request, $this->year->id)->getData(true)['data'];
        $this->assertSame('20.5', $saved['curriculo'][0]['peso_area']);

        [$openPeriod, $enrollment, $activity] = $this->gradeFixture();
        (new GradebookService)->saveGrades($this->rector, $this->assignment, $openPeriod->id, [[
            'matricula_id' => $enrollment->id, 'actividad_id' => $activity->id,
            'valor' => '4', 'version' => 0,
        ]]);
        $openPeriod->update(['estado' => 'cerrado']);

        $locked = $controller->show($this->year->id)->getData(true)['data'];
        $this->assertFalse($locked['curriculo_editable']);
        $request = Request::create('/', 'PUT', ['grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id), 'peso_area' => '7.25']);
        $request->setUserResolver(fn () => $this->rector);
        try {
            $controller->curriculo($request, $this->year->id);
            $this->fail('Un período cerrado con notas debe preservar el peso del currículo.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('20.5', DB::table('materias_curriculares')->value('peso_area'));
    }
}
