<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Grado;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Services\AcademicPromotionService;
use App\Services\AcademicRecoveryService;
use App\Services\AcademicYearReviewService;
use App\Services\DuplicarAnoLectivoService;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One isolated student across the actual period, bulletin and annual transitions. */
trait AcademicYearJourneyTests
{
    public function test_two_period_year_recovery_bulletins_promotion_closure_and_next_year_copy(): void
    {
        Storage::fake('tenant');
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$first, $enrollment, $firstActivity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 2]);
        $first->update(['fecha_fin' => '2026-06-30']);
        $second = Periodo::create(['ano_lectivo_id' => $this->year->id,
            'nombre' => 'Segundo período', 'orden' => 2, 'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31', 'estado' => Periodo::ESTADO_ABIERTO]);
        $secondComponent = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id,
            'periodo_id' => $second->id, 'nombre' => 'Talleres', 'modo' => 'SIMPLE_AVERAGE']);
        $secondActivity = ActividadEvaluacion::create(['componente_id' => $secondComponent->id,
            'nombre' => 'Taller 2', 'fecha' => '2026-08-05']);
        $targetGrade = Grado::create(['ano_lectivo_id' => $this->year->id,
            'nivel_id' => $this->group->grado->nivel_id, 'nombre' => 'Segundo',
            'codigo' => '02', 'estado' => 'activo']);
        foreach (['notas.gestionar_nivelaciones', 'boletines.politica.gestionar',
            'boletines.observaciones.gestionar', 'boletines.observaciones.aprobar',
            'boletines.emisiones.ver', 'boletines.emisiones.preparar',
            'boletines.emisiones.aprobar', 'boletines.emisiones.publicar'] as $permission) {
            $this->rector->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('year-journey')->plainTextToken);
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $observationUrl = 'http://localhost/api/boletines/observaciones/'.$enrollmentToken.'?opaque=1';
        $issueBase = 'http://localhost/api/boletines/emisiones/';
        $review = app(AcademicYearReviewService::class);
        $periodController = app(PeriodoController::class);
        $transition = Request::create('/', 'POST', ['confirmar_promociones' => true]);
        $transition->setUserResolver(fn () => $this->rector);

        $this->putJson('http://localhost/api/boletines/politica?opaque=1', [
            'version' => 1, 'requiere_observacion_final' => true,
            'docente_edita_indicadores_grupo' => false,
        ])->assertOk();
        $promotion = app(AcademicPromotionService::class);
        $promotion->savePolicy($this->rector, $this->year, 0, [], null, 0);
        $this->assertFalse($review->review($this->year->fresh())['puede_cerrar']);

        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $first->id, [[
            'actividad_id' => $firstActivity->id, 'matricula_id' => $enrollment->id,
            'valor' => '2', 'version' => 0,
        ]]);
        $periodController->cerrar($transition, (string) $first->id);
        $first->refresh();
        $this->assertSame('pendiente', $promotion->proposal($this->year->fresh(), $enrollment)['estado']);
        $recovery = app(AcademicRecoveryService::class)->create($this->rector, $enrollment,
            $this->assignment, $first, 'Nivelación del primer período');
        app(AcademicRecoveryService::class)->record($this->teacher, $recovery, '4', null, 1,
            'Resultado de nivelación revisado');
        $firstSelection = ['contexto' => 'periodo',
            'periodo_token' => OpaqueUrlToken::for('periodo', $first->id)];
        $this->postJson($issueBase.$enrollmentToken.'/preparar?opaque=1', $firstSelection)
            ->assertUnprocessable();
        $this->putJson($observationUrl, [...$firstSelection, 'contenido' => 'Avance en el primer período.',
            'version' => 0])->assertOk();
        $this->postJson('http://localhost/api/boletines/observaciones/'.$enrollmentToken.'/aprobar?opaque=1',
            [...$firstSelection, 'version' => 1])->assertOk();
        $firstIssue = $this->postJson($issueBase.$enrollmentToken.'/preparar?opaque=1', $firstSelection)
            ->assertOk()->json('data.token');
        $this->postJson($issueBase.'documentos/'.$firstIssue.'/aprobar?opaque=1')->assertOk();
        $this->postJson($issueBase.'documentos/'.$firstIssue.'/publicar?opaque=1')->assertOk();
        $firstSnapshot = DB::table('boletin_emisiones')->where('contexto', 'periodo:'.$first->id)
            ->firstOrFail()->snapshot;
        $this->assertSame('4', json_decode($firstSnapshot, true, 512, JSON_THROW_ON_ERROR)
            ['asignaturas'][0]['resultado']['exact_value']);

        // The first published PDF remains frozen while the rest of the year proceeds.
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $second->id, [[
            'actividad_id' => $secondActivity->id, 'matricula_id' => $enrollment->id,
            'valor' => '4', 'version' => 0,
        ]]);
        $secondSelection = ['contexto' => 'periodo',
            'periodo_token' => OpaqueUrlToken::for('periodo', $second->id)];
        $this->putJson($observationUrl, [...$secondSelection, 'contenido' => 'Cierre del segundo período.',
            'version' => 0])->assertOk();
        $this->postJson('http://localhost/api/boletines/observaciones/'.$enrollmentToken.'/aprobar?opaque=1',
            [...$secondSelection, 'version' => 1])->assertOk();
        $this->putJson($observationUrl, ['contexto' => 'final',
            'contenido' => 'Balance anual del estudiante.', 'version' => 0])->assertOk();
        $this->postJson('http://localhost/api/boletines/observaciones/'.$enrollmentToken.'/aprobar?opaque=1',
            ['contexto' => 'final', 'version' => 1])->assertOk();
        $this->assertFalse($review->review($this->year->fresh())['puede_cerrar']);
        $periodController->cerrar($transition, (string) $second->id);
        $secondIssue = $this->postJson($issueBase.$enrollmentToken.'/preparar?opaque=1', $secondSelection)
            ->assertOk()->json('data.token');
        $this->postJson($issueBase.'documentos/'.$secondIssue.'/aprobar?opaque=1')->assertOk();
        $this->postJson($issueBase.'documentos/'.$secondIssue.'/publicar?opaque=1')->assertOk();
        $this->assertContains('promotion_pending', $review->review($this->year->fresh())['bloqueos']);
        try {
            app(AnoLectivoController::class)->cerrar($transition, (string) $this->year->id);
            $this->fail('El año no debe cerrarse sin revisar la promoción.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertSame(AnoLectivo::ESTADO_EN_CURSO, $this->year->fresh()->estado);
        $proposal = $promotion->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('promovido', $proposal['resultado']);
        $decision = $promotion->approve($this->rector, $this->year, $enrollment,
            $proposal['huella'], 'promovido', $targetGrade, 'Revisión del año completo', 0);
        $this->assertTrue($promotion->isCurrent($this->year->fresh(), $enrollment, $decision));
        $this->assertTrue($review->review($this->year->fresh())['puede_cerrar']);
        app(AnoLectivoController::class)->cerrar($transition, (string) $this->year->id);
        $this->assertSame(AnoLectivo::ESTADO_CERRADO, $this->year->fresh()->estado);

        $finalIssue = $this->postJson($issueBase.$enrollmentToken.'/preparar?opaque=1',
            ['contexto' => 'final'])->assertOk()->json('data.token');
        $this->postJson($issueBase.'documentos/'.$finalIssue.'/aprobar?opaque=1')->assertOk();
        $this->postJson($issueBase.'documentos/'.$finalIssue.'/publicar?opaque=1')->assertOk();
        $final = DB::table('boletin_emisiones')->where('contexto', 'final')->firstOrFail();
        $snapshot = json_decode($final->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('promovido', $snapshot['promocion']['resultado']);
        $this->assertSame('Balance anual del estudiante.', $snapshot['observacion']);
        $this->assertSame('4', $snapshot['asignaturas'][0]['resultado']['exact_value']);
        $this->assertCount(2, app(GradebookService::class)->report($enrollment)['asignaturas'][0]['periodos']);
        Storage::disk('tenant')->assertExists($final->pdf_path);
        $this->assertSame(hash('sha256', Storage::disk('tenant')->get($final->pdf_path)), $final->pdf_sha256);
        $this->assertSame('2', (string) DB::table('calificaciones')
            ->where('matricula_id', $enrollment->id)->orderBy('id')->value('valor'));
        $this->assertSame('4', json_decode(DB::table('boletin_emisiones')
            ->where('contexto', 'periodo:'.$first->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR)
            ['asignaturas'][0]['resultado']['exact_value']);

        $next = app(DuplicarAnoLectivoService::class)->duplicar($this->year, [
            'nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-12-31', 'num_periodos' => 2, 'periodo_sumatorio' => false,
        ], ['curriculo' => true, 'periodos' => true, 'siee' => true], $this->rector);
        $this->assertSame(AnoLectivo::ESTADO_PLANIFICADO, $next->estado);
        $this->assertSame(2, Periodo::where('ano_lectivo_id', $next->id)->count());
        $this->assertSame(0, Matricula::where('ano_lectivo_id', $next->id)->count());
        $this->assertSame(1, Matricula::where('estudiante_id', $enrollment->estudiante_id)->count());
        $this->assertSame(0, DB::table('asignaciones_docentes')->where('ano_lectivo_id', $next->id)->count());
        $this->assertSame(0, DB::table('calificaciones')->where('matricula_id', '!=', $enrollment->id)->count());
        $this->assertSame(0, DB::table('boletin_emisiones')->where('matricula_id', '!=', $enrollment->id)->count());
    }
}
