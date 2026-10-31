<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BoletinEmision;
use App\Models\Academico\Periodo;
use App\Services\AcademicRecoveryService;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait OpenPeriodRecoveryTests
{
    public static function teacherBulletinRecoveryPolicies(): array
    {
        // Independent expected values: original 2.0, recovery 4.0, passing grade 3.0.
        return [
            'reemplazar (SIEE actual de Inmaculada)' => ['REPLACE', null, '4', '4', '4.0'],
            'promediar original y recuperacion' => ['AVERAGE', null, '3', '3', '3.0'],
            'limitar a la nota minima aprobatoria' => ['MAX_PASSING_GRADE', null, '3', '3', '3.0'],
            'definitiva manual del SIEE' => ['MANUAL', '3.5', '7/2', '3.5', '3.5'],
        ];
    }

    #[DataProvider('teacherBulletinRecoveryPolicies')]
    public function test_teacher_full_open_period_recovery_observation_and_pdf_use_siee_result(
        string $policy, ?string $manual, string $expectedExact, string $expectedDisplay, string $expectedBulletinDisplay
    ): void {
        Storage::fake('tenant');
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['siee' => [...$this->year->siee, 'recuperacion' => $policy]]);
        $enrollment->estudiante->update(['name' => 'Estudiante de prueba - sin validez academica']);
        foreach (['boletines.observaciones.gestionar', 'boletines.emisiones.preparar', 'boletines.emisiones.ver'] as $key) {
            $this->teacher->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }
        $this->assertSame('docente', $this->teacher->role);
        $this->assertFalse($this->teacher->can('notas.editar_no_dicta'));
        $this->assertFalse($this->teacher->can('boletines.observaciones.aprobar'));
        $this->assertFalse($this->teacher->can('boletines.emisiones.aprobar'));
        // Exercise the real HTTP controllers with a teacher session, never a rector token.
        $this->withHeader('X-Tenant', $this->school->id)->actingAs($this->teacher, 'web');
        $studentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $assignmentToken = OpaqueUrlToken::for('asignacion-docente', $this->assignment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $api = 'http://localhost/api';

        $this->putJson("{$api}/evaluacion/planillas/{$assignmentToken}/{$periodToken}?opaque=1", [
            'notas' => [['actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
                'matricula_token' => $studentToken, 'valor' => '2.0', 'version' => 0]],
        ])->assertOk();
        $this->getJson("{$api}/evaluacion/recuperaciones?opaque=1&matricula_token={$studentToken}")
            ->assertOk()->assertJsonPath('can_manage', true)
            ->assertJsonPath('candidatos.0.asignacion_token', $assignmentToken)
            ->assertJsonPath('candidatos.0.puede_abrir', true);
        $recoveryToken = $this->postJson("{$api}/evaluacion/recuperaciones?opaque=1", [
            'matricula_token' => $studentToken, 'asignacion_token' => $assignmentToken,
            'periodo_token' => $periodToken, 'plan_mejoramiento' => 'Refuerzo de prueba del aprendizaje.',
        ])->assertCreated()->assertJsonPath('data.politica', $policy)->json('data.url_token');
        $this->putJson("{$api}/evaluacion/recuperaciones/{$recoveryToken}?opaque=1", [
            'nota_recuperacion' => '4.0', 'nota_manual' => $manual, 'version' => 1,
            'motivo' => 'Demostró los aprendizajes en el refuerzo de prueba.',
        ])->assertOk()->assertJsonPath('data.estado', 'aprobada')
            ->assertJsonPath('data.valor_original_exacto', '2')
            ->assertJsonPath('data.valor_efectivo_exacto', $expectedExact);

        $observation = 'PRUEBA AISLADA. Demuestra los aprendizajes después del refuerzo. Sin validez académica.';
        $selection = ['contexto' => 'periodo', 'periodo_token' => $periodToken];
        $this->putJson("{$api}/boletines/observaciones/{$studentToken}?opaque=1", [
            ...$selection, 'contenido' => $observation, 'version' => 0,
        ])->assertOk()->assertJsonPath('data.contenido', $observation);
        $this->getJson("{$api}/evaluacion/boletines/{$studentToken}?opaque=1")->assertOk()
            ->assertJsonPath('data.asignaturas.0.periodos.0.display_value', $expectedDisplay)
            ->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'recuperacion');
        $issueToken = $this->postJson("{$api}/boletines/emisiones/{$studentToken}/emitir?opaque=1", $selection)
            ->assertOk()->assertJsonPath('data.pdf_disponible', true)->json('data.token');
        $issue = BoletinEmision::sole();
        $result = $issue->snapshot['asignaturas'][0]['resultado'];
        $this->assertSame($expectedExact, $result['exact_value']);
        // The API presenter normalizes decimals; the PDF retains the SIEE's one decimal place.
        $this->assertSame($expectedBulletinDisplay, $result['display_value']);
        $this->assertSame('2', $result['resultado_original']['exact_value']);
        $this->assertSame($policy, $result['politica_recuperacion']);
        $this->assertSame('recuperacion', $result['origen']);
        $this->assertSame($observation, $issue->snapshot['observacion']);
        $this->assertSame($this->teacher->id, $issue->prepared_by);
        $this->assertSame($this->teacher->id, $issue->approved_by);
        $this->assertDatabaseHas('observaciones_boletin', ['matricula_id' => $enrollment->id,
            'updated_by' => $this->teacher->id, 'aprobado_por' => null, 'version' => 1]);

        $pdf = $this->getJson("{$api}/boletines/emisiones/documentos/{$issueToken}/pdf?opaque=1")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame($issue->pdf_sha256, hash('sha256', $pdf));
        $this->assertSame(Storage::disk('tenant')->get($issue->pdf_path), $pdf);
        $this->assertSame('abierto', $period->fresh()->estado);
        $this->assertSame('en_curso', $this->year->fresh()->estado);
        $this->assertSame('2', (string) DB::table('calificaciones')->value('valor'));
        $this->assertSame('4', (string) DB::table('recuperaciones_academicas')->value('nota_recuperacion'));

        // Optional evidence export for visual/PDF-text QA; no live tenant data is used.
        if (getenv('BULLETIN_FLOW_EXPORT') === '1') {
            $directory = base_path('tmp/pdfs/docente-periodo-abierto');
            File::ensureDirectoryExists($directory);
            File::put($directory.'/'.$policy.'.pdf', $pdf);
        }
    }

    public function test_open_recovery_closure_locks_normal_changes_but_keeps_annual_habilitation(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->year->update(['num_periodos' => 1]);
        $book = app(GradebookService::class);
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '2', 'version' => 0,
        ]]);
        $this->teacher->givePermissionTo(Permission::findOrCreate('boletines.observaciones.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->teacher->createToken('open-recovery')->plainTextToken);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $base = 'http://localhost/api/evaluacion/recuperaciones';
        $selection = ['matricula_token' => $token,
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $this->assignment->id),
            'periodo_token' => $periodToken];
        $created = $this->postJson($base.'?opaque=1', $selection)->assertCreated()->json('data.url_token');
        $recordUrl = $base.'/'.$created.'?opaque=1';
        $this->putJson($recordUrl, ['nota_recuperacion' => '2.5', 'version' => 1, 'motivo' => 'Refuerzo inicial'])
            ->assertOk()->assertJsonPath('data.estado', 'no_aprobada');
        $observationUrl = 'http://localhost/api/boletines/observaciones/'.$token.'?opaque=1';
        $observation = ['contexto' => 'periodo', 'periodo_token' => $periodToken,
            'contenido' => 'Continúa su proceso de aprendizaje.', 'version' => 0];
        $this->putJson($observationUrl, $observation)->assertOk();
        $this->getJson($base.'?opaque=1&matricula_token='.$token)->assertOk()
            ->assertJsonPath('data.0.can_record', true)->assertJsonPath('data.0.can_cancel', true);

        // Habilitación is not an alternative way to edit an open term.
        $annual = [...$selection, 'periodo_token' => null];
        $this->postJson($base.'?opaque=1', $annual)->assertUnprocessable();
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $this->putJson($recordUrl, ['nota_recuperacion' => '4', 'version' => 2, 'motivo' => 'Cambio tardío'])
            ->assertUnprocessable()->assertJsonPath('message', 'La nivelación solo se puede crear, modificar o anular con el período abierto.');
        $this->postJson($base.'/'.$created.'/anular?opaque=1', ['version' => 2, 'motivo' => 'Anulación tardía'])
            ->assertUnprocessable();
        $this->postJson($base.'?opaque=1', $selection)->assertUnprocessable();
        $this->putJson($observationUrl, [...$observation, 'version' => 1, 'contenido' => 'Cambio tardío'])
            ->assertUnprocessable();
        $this->getJson($observationUrl)->assertOk()->assertJsonPath('data.periodos.0.puede_editar', false);
        $this->assertAcademicBlocked(fn () => $book->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '5', 'version' => 1,
        ]]), 422);
        $closed = $this->getJson($base.'?opaque=1&matricula_token='.$token)->assertOk()
            ->assertJsonPath('data.0.can_record', false)->assertJsonPath('data.0.can_cancel', false)->json();
        $this->assertNotEmpty($closed['data'][0]['motivo_bloqueo']);
        $this->assertSame('habilitacion', $closed['candidatos'][0]['tipo']);
        $habilitation = $this->postJson($base.'?opaque=1', $annual)->assertCreated()->json('data.url_token');
        $this->putJson($base.'/'.$habilitation.'?opaque=1', [
            'nota_recuperacion' => '4', 'version' => 1, 'motivo' => 'Habilitación anual SIEE',
        ])->assertOk();
        $report = $book->report($enrollment);
        $this->assertSame('5/2', $report['asignaturas'][0]['periodos'][0]['exact_value']);
        $this->assertSame('4', $report['asignaturas'][0]['anual']['exact_value']);
        $this->assertSame('2', (string) DB::table('calificaciones')->value('valor'));
        $this->year->update(['estado' => AnoLectivo::ESTADO_CERRADO]);
        $this->putJson($base.'/'.$habilitation.'?opaque=1', [
            'nota_recuperacion' => '5', 'version' => 2, 'motivo' => 'Año cerrado',
        ])->assertUnprocessable();
        $this->postJson($base.'/'.$habilitation.'/anular?opaque=1', ['version' => 2, 'motivo' => 'Año cerrado'])
            ->assertUnprocessable();
    }

    public function test_recovery_permission_does_not_bypass_teacher_assignment_even_for_group_director(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '2', 'version' => 0,
        ]]);
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->teacher->createToken('scoped-recovery')->plainTextToken);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $base = 'http://localhost/api/evaluacion/recuperaciones';
        $selection = ['matricula_token' => $token, 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $this->assignment->id)];
        $created = $this->postJson($base.'?opaque=1', $selection)->assertCreated()->json('data.url_token');
        Role::findByName('docente', 'web')->revokePermissionTo('notas.gestionar_nivelaciones');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app('auth')->forgetGuards();
        $this->getJson($base.'?opaque=1&matricula_token='.$token)->assertOk()
            ->assertJsonPath('can_manage', false)->assertJsonPath('data.0.can_record', false);
        $this->putJson($base.'/'.$created.'?opaque=1', ['nota_recuperacion' => '4', 'version' => 1, 'motivo' => 'Sin permiso'])
            ->assertForbidden();
        $this->postJson($base.'?opaque=1', $selection)->assertForbidden();
        $this->postJson($base.'/'.$created.'/anular?opaque=1', ['version' => 1, 'motivo' => 'Sin permiso'])
            ->assertForbidden();

        Role::findByName('docente', 'web')->givePermissionTo('notas.gestionar_nivelaciones');
        $this->assignment->update(['docente_id' => $this->rector->id]);
        DB::table('directores_grupo')->insert(['grupo_id' => $this->group->id, 'docente_id' => $this->teacher->id,
            'actualizado_por' => $this->rector->id, 'version' => 1, 'asignado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app('auth')->forgetGuards();
        $this->getJson($base.'?opaque=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?opaque=1&matricula_token='.$token)->assertForbidden();
        $this->postJson($base.'?opaque=1', $selection)->assertForbidden();
        $this->putJson($base.'/'.$created.'?opaque=1', ['nota_recuperacion' => '4', 'version' => 1, 'motivo' => 'Otra materia'])
            ->assertForbidden();
        $this->postJson($base.'/'.$created.'/anular?opaque=1', ['version' => 1, 'motivo' => 'Otra materia'])
            ->assertForbidden();
    }

    public function test_open_recovery_rejects_stale_original_until_cancelled_and_recreated(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $book = app(GradebookService::class);
        $service = app(AcademicRecoveryService::class);
        $grade = ['actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '2', 'version' => 0];
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [$grade]);
        $recovery = $service->create($this->teacher, $enrollment, $this->assignment, $period);
        $book->saveGrades($this->teacher, $this->assignment, $period->id, [[...$grade, 'valor' => '2.5', 'version' => 1]]);
        $this->assertAcademicBlocked(fn () => $service->record($this->teacher, $recovery, '4', null, 1, 'Nota cambió'), 409);
        $service->cancel($this->teacher, $recovery, 1, 'Revisar nueva nota original');
        $updated = $service->create($this->teacher, $enrollment, $this->assignment, $period);
        $this->assertSame('5/2', $updated->valor_original_exacto);
        $service->record($this->teacher, $updated, '4', null, $updated->version, 'Refuerzo revisado');
        $this->assertSame('4', $book->report($enrollment)['asignaturas'][0]['periodos'][0]['exact_value']);
    }

    private function assertAcademicBlocked(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('La acción debía rechazarse.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }
}
