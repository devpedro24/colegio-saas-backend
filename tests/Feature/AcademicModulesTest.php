<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Controllers\Api\Academico\EventoController;
use App\Http\Controllers\Api\Academico\HorarioController;
use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Api\Academico\SieeController;
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
use App\Models\Academico\Sede;
use App\Models\Academico\SesionHorario;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionMatrix;
use App\Services\EventAccess;
use App\Services\GradebookService;
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
        $this->assertSame(Periodo::ESTADO_CERRADO,
            $periods->cerrar($request, (string) $period->id)->getData(true)['data']['estado']);
        $this->assertSame(AnoLectivo::ESTADO_CERRADO,
            $years->cerrar($request, (string) $this->year->id)->getData(true)['data']['estado']);
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
        $configuration = [...SieeConfiguration::DEFAULTS, 'escala_id' => $scale->id,
            'metodo_id' => $method->id];
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
        DB::table('materias_curriculares')->insert([
            'ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'materia_id' => $this->subject->id, 'area_id' => $this->subject->area_id,
        ]);

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
        DB::table('materias_curriculares')->insert([
            'ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'materia_id' => $this->subject->id, 'area_id' => $this->subject->area_id,
        ]);
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
        // Conexión tenant real separada de la central, ambas SQLite en memoria.
        config(['tenancy.bootstrappers' => [], 'database.connections.academic_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'academic-test', 'name' => 'Colegio', 'slug' => 'academic-test', 'plan' => 'esencial', 'tipo' => 'colegio', 'status' => 'active']));
        DB::setDefaultConnection('academic_test');
        Artisan::call('migrate', ['--database' => 'academic_test', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize($this->school);
        Role::findOrCreate('docente', 'web');
        $rectorRole = Role::findOrCreate('rector', 'web');
        foreach (['academico.anos.transicionar', 'academico.periodos.transicionar'] as $permission) {
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
        $otherSubject = Materia::create(['nombre' => 'Lenguaje inicial', 'area_id' => $this->subject->area_id, 'nivel_id' => $preescolar->id, 'intensidad_horaria' => 5, 'estado' => 'activo']);
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
