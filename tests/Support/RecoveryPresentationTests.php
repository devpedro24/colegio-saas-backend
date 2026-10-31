<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Periodo;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

trait RecoveryPresentationTests
{
    public static function recoveryApprovalValues(): array
    {
        return [['3.1', true], ['3.2', true], ['3.0', true], ['2.8', false],
            ['2.95', false], ['2.9501', true]];
    }

    private function recoveryVisualScale(): array
    {
        $level = $this->group->grado->nivel->nivel_educativo;
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nivel_educativo' => $level, 'nombre' => 'Caritas', 'tipo' => 'imagenes']);
        $high = EscalaOpcion::create(['escala_id' => $scale->id, 'nombre' => 'Logrado',
            'orden' => 1, 'valor_equivalente' => '5.00', 'emoji' => '😄', 'aprueba' => true]);
        $low = EscalaOpcion::create(['escala_id' => $scale->id, 'nombre' => 'En proceso',
            'orden' => 2, 'valor_equivalente' => '3.20', 'emoji' => '😐', 'aprueba' => false]);

        return [$scale, $high, $low];
    }

    private function recoveryHttpFixture(string $value): array
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
            'valor' => $value, 'version' => 0,
        ]]);
        $this->withHeader('X-Tenant', $this->school->id)->actingAs($this->teacher, 'web');
        $scope = ['matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $this->assignment->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)];
        $api = 'http://localhost/api/evaluacion/recuperaciones';

        return [$api, $scope, $period, $enrollment];
    }

    #[DataProvider('recoveryApprovalValues')]
    public function test_recovery_candidates_catalog_and_creation_share_siee_approval_with_faces(string $grade, bool $passed): void
    {
        [$api, $scope, , $enrollment] = $this->recoveryHttpFixture($grade);
        $this->recoveryVisualScale();
        $book = app(GradebookService::class);
        $this->assertSame($passed, $book->report($enrollment)['asignaturas'][0]['periodos'][0]['aprobado']);
        $this->assertSame($passed ? [] : [$enrollment->id], $book->failedEnrollmentIds(collect([$enrollment])));
        $this->getJson("{$api}?opaque=1&matricula_token={$scope['matricula_token']}")->assertOk()
            ->assertJsonCount($passed ? 0 : 1, 'candidatos');
        $response = $this->postJson("{$api}?opaque=1", $scope);
        $passed ? $response->assertUnprocessable() : $response->assertCreated();
    }

    public function test_visual_recovery_uses_scoped_choices_and_preserves_exact_audited_result(): void
    {
        [$api, $scope, $period, $enrollment] = $this->recoveryHttpFixture('2.64375');
        [, $choice] = $this->recoveryVisualScale();
        $choiceToken = OpaqueUrlToken::for('escala-opcion', $choice->id);
        $candidate = $this->getJson("{$api}?opaque=1&matricula_token={$scope['matricula_token']}")->assertOk();
        $candidate->assertJsonPath('candidatos.0.valor_original', null)
            ->assertJsonPath('candidatos.0.valoracion_original.nombre', 'En proceso');
        $token = $this->postJson("{$api}?opaque=1", $scope)->assertCreated()
            ->assertJsonPath('data.valor_original_exacto', '423/160')
            ->assertJsonPath('data.resultado_original.display_value', null)
            ->assertJsonPath('data.escala_visual.tipo', 'imagenes')->json('data.url_token');
        $payload = ['version' => 1, 'motivo' => 'Valoración después del refuerzo.'];
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'nota_recuperacion' => '5.0'])->assertUnprocessable();
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'escala_opcion_token' => str_repeat('x', 24)])->assertUnprocessable();
        $foreignScale = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Otra escala', 'tipo' => 'imagenes']);
        $foreign = EscalaOpcion::create(['escala_id' => $foreignScale->id, 'nombre' => 'Ajena',
            'orden' => 1, 'valor_equivalente' => '5', 'emoji' => '😄', 'aprueba' => true]);
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload,
            'escala_opcion_token' => OpaqueUrlToken::for('escala-opcion', $foreign->id)])->assertUnprocessable();
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'escala_opcion_token' => $choiceToken,
            'escala_opcion_manual_token' => $choiceToken])->assertUnprocessable();
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'escala_opcion_token' => $choiceToken])->assertOk()
            ->assertJsonPath('data.estado', 'aprobada')
            ->assertJsonPath('data.resultado_recuperacion.valoracion.url_token', $choiceToken)
            ->assertJsonPath('data.resultado_efectivo.valoracion.url_token', $choiceToken)
            ->assertJsonPath('data.valor_efectivo_exacto', '5')->assertJsonPath('data.version', 2);
        $this->assertSame('5', app(GradebookService::class)->report($enrollment)['asignaturas'][0]['periodos'][0]['exact_value']);
        $this->assertDatabaseHas('recuperaciones_academicas', ['resolved_by' => $this->teacher->id,
            'valor_original_exacto' => '423/160', 'version' => 2]);
        $audit = DB::table('audit_logs')->where('recurso', 'recuperacion_academica')
            ->where('accion', 'UPDATE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($choice->id, json_decode($audit->valor_nuevo, true)['valoracion_recuperacion']['escala_opcion_id']);
        $period->update(['estado' => 'cerrado']);
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'version' => 2, 'escala_opcion_token' => $choiceToken])->assertUnprocessable();
    }

    public function test_numeric_recovery_presents_one_decimal_and_keeps_exact_fraction_internal(): void
    {
        [$api, $scope] = $this->recoveryHttpFixture('2.64375');
        $token = $this->postJson("{$api}?opaque=1", $scope)->assertCreated()
            ->assertJsonPath('data.resultado_original.display_value', '2.6')->json('data.url_token');
        $this->putJson("{$api}/{$token}?opaque=1", ['version' => 1, 'motivo' => 'Recuperación numérica',
            'nota_recuperacion' => '5.0'])->assertOk()
            ->assertJsonPath('data.valor_original_exacto', '423/160')
            ->assertJsonPath('data.resultado_recuperacion.display_value', '5.0')
            ->assertJsonPath('data.resultado_efectivo.display_value', '5.0');
    }

    public function test_visual_manual_recovery_requires_both_choices_and_uses_the_manual_equivalent(): void
    {
        [$api, $scope] = $this->recoveryHttpFixture('2.8');
        $this->year->update(['siee' => [...$this->year->siee, 'recuperacion' => 'MANUAL']]);
        [, $high, $low] = $this->recoveryVisualScale();
        $highToken = OpaqueUrlToken::for('escala-opcion', $high->id);
        $lowToken = OpaqueUrlToken::for('escala-opcion', $low->id);
        $token = $this->postJson("{$api}?opaque=1", $scope)->assertCreated()->json('data.url_token');
        $payload = ['version' => 1, 'motivo' => 'Definitiva manual según SIEE.', 'escala_opcion_token' => $highToken];
        $this->putJson("{$api}/{$token}?opaque=1", $payload)->assertUnprocessable();
        $this->putJson("{$api}/{$token}?opaque=1", [...$payload, 'escala_opcion_manual_token' => $lowToken])->assertOk()
            ->assertJsonPath('data.resultado_recuperacion.valoracion.url_token', $highToken)
            ->assertJsonPath('data.resultado_manual.valoracion.url_token', $lowToken)
            ->assertJsonPath('data.valor_efectivo_exacto', '16/5')
            ->assertJsonPath('data.estado', 'aprobada');
    }

    public function test_recovery_candidates_and_history_are_chronological_not_creation_order(): void
    {
        [$api, $scope, $first, $enrollment] = $this->recoveryHttpFixture('2.8');
        $periods = [];
        foreach ([3, 2] as $order) {
            $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => "Período {$order}",
                'orden' => $order, 'estado' => 'abierto', 'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-12-31']);
            $component = ComponenteEvaluacion::create(['asignacion_id' => $this->assignment->id,
                'periodo_id' => $period->id, 'nombre' => 'Actividades', 'modo' => 'SIMPLE_AVERAGE']);
            $activity = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Refuerzo', 'fecha' => '2026-06-01']);
            app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
                'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '2.8', 'version' => 0,
            ]]);
            $periods[] = $period;
        }
        $url = "{$api}?opaque=1&matricula_token={$scope['matricula_token']}";
        $this->assertSame([1, 2, 3], array_column($this->getJson($url)->assertOk()->json('candidatos'), 'periodo_orden'));
        foreach ([...$periods, $first] as $period) {
            $this->postJson("{$api}?opaque=1", [...$scope, 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)])->assertCreated();
        }
        $this->assertSame([1, 2, 3], array_column($this->getJson($url)->assertOk()->json('data'), 'periodo_orden'));
    }
}
