<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;

/** Recuperaciones separadas de la calificación original; nunca reabre una planilla. */
final class AcademicRecoveryService
{
    public function __construct(private GradebookService $book, private GradeCalculationService $calculator) {}

    public function create(User $actor, Matricula $enrollment, AsignacionDocente $assignment,
        ?Periodo $period, ?string $plan = null): RecuperacionAcademica
    {
        abort_unless($actor->can('notas.gestionar_nivelaciones'), 403);

        return DB::transaction(function () use ($actor, $enrollment, $assignment, $period, $plan) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($enrollment->ano_lectivo_id);
            $enrollment = Matricula::lockForUpdate()->findOrFail($enrollment->id);
            $this->assertScope($year, $enrollment, $assignment, $period);
            $config = app(SieeConfiguration::class)->resolve($year);
            $original = $this->original($enrollment, $assignment, $period, $config);
            $scope = $period ? 'periodo:'.$period->id : 'anual';
            $existing = RecuperacionAcademica::where('matricula_id', $enrollment->id)
                ->where('asignacion_id', $assignment->id)->where('alcance', $scope)->lockForUpdate()->first();
            abort_if($existing && $existing->estado !== 'anulada', 422,
                'Ya existe una recuperación para esta materia y período o año.');
            $attributes = [
                'ano_lectivo_id' => $year->id, 'matricula_id' => $enrollment->id,
                'asignacion_id' => $assignment->id, 'periodo_id' => $period?->id,
                'alcance' => $scope, 'tipo' => $period ? 'nivelacion' : 'habilitacion',
                'estado' => 'pendiente', 'politica' => $config['recuperacion'],
                'valor_original_exacto' => $original['exact_value'],
                'nota_recuperacion' => null, 'nota_manual' => null,
                'valor_efectivo_exacto' => null, 'plan_mejoramiento' => $plan,
                'motivo' => null, 'created_by' => $actor->id,
                'resolved_by' => null, 'cancelled_by' => null,
                'version' => ($existing?->version ?? 0) + 1,
            ];
            $before = $existing?->toArray();
            $recovery = $existing ?? new RecuperacionAcademica;
            $recovery->fill($attributes)->save();
            AuditLogger::tenant($actor, $before ? 'UPDATE' : 'CREATE', 'recuperacion_academica',
                (string) $recovery->id, $before, $recovery->toArray(),
                $before ? 'Reapertura de una recuperación anulada' : 'Apertura de recuperación');

            return $recovery;
        });
    }

    public function record(User $actor, RecuperacionAcademica $recovery, string $grade,
        ?string $manual, int $version, string $reason): RecuperacionAcademica
    {
        return DB::transaction(function () use ($actor, $recovery, $grade, $manual, $version, $reason) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($recovery->ano_lectivo_id);
            $recovery = RecuperacionAcademica::lockForUpdate()->findOrFail($recovery->id);
            $assignment = AsignacionDocente::findOrFail($recovery->asignacion_id);
            $this->authorizeRecorder($actor, $assignment);
            abort_unless($recovery->version === $version, 409,
                'Otra persona actualizó esta recuperación. Recarga antes de guardar.');
            abort_if($recovery->estado === 'anulada', 422, 'La recuperación anulada no admite notas.');
            $enrollment = Matricula::findOrFail($recovery->matricula_id);
            $period = $recovery->periodo_id ? Periodo::findOrFail($recovery->periodo_id) : null;
            $this->assertScope($year, $enrollment, $assignment, $period);
            $config = app(SieeConfiguration::class)->resolve($year);
            abort_unless($config['recuperacion'] === $recovery->politica, 409,
                'La política SIEE cambió. Revisa esta recuperación antes de registrar la nota.');
            $original = $this->original($enrollment, $assignment, $period, $config);
            abort_unless($original['exact_value'] === $recovery->valor_original_exacto, 409,
                'El resultado original cambió. Revisa la recuperación antes de guardar.');
            $this->assertGradeInScale($grade, $config);
            if ($recovery->politica === 'MANUAL') {
                abort_if($manual === null, 422, 'Indica la nota definitiva manual de la recuperación.');
                $this->assertGradeInScale($manual, $config);
            } else {
                abort_if($manual !== null, 422, 'La política configurada no admite una nota definitiva manual.');
            }
            $effective = $this->calculator->recover($original['exact_value'], $grade,
                $recovery->politica, (string) $config['nota_minima'], $manual);
            $this->assertGradeInScale((string) $effective, $config);
            $before = $recovery->toArray();
            $recovery->fill([
                'nota_recuperacion' => $grade, 'nota_manual' => $manual,
                'valor_efectivo_exacto' => (string) $effective->simplified(),
                'estado' => $this->calculator->result(['mode' => 'MANUAL', 'manual' => (string) $effective], $config)['aprobado']
                    ? 'aprobada' : 'no_aprobada',
                'motivo' => $reason, 'resolved_by' => $actor->id,
                'version' => $recovery->version + 1,
            ])->save();
            AuditLogger::tenant($actor, 'UPDATE', 'recuperacion_academica',
                (string) $recovery->id, $before, $recovery->toArray(), $reason);

            return $recovery;
        });
    }

    public function cancel(User $actor, RecuperacionAcademica $recovery, int $version, string $reason): RecuperacionAcademica
    {
        abort_unless($actor->can('notas.gestionar_nivelaciones'), 403);

        return DB::transaction(function () use ($actor, $recovery, $version, $reason) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($recovery->ano_lectivo_id);
            $recovery = RecuperacionAcademica::lockForUpdate()->findOrFail($recovery->id);
            abort_unless($year->estado === AnoLectivo::ESTADO_EN_CURSO, 422,
                'No se puede anular una recuperación de un año cerrado.');
            abort_unless($recovery->version === $version, 409,
                'Otra persona actualizó esta recuperación. Recarga antes de anular.');
            abort_if($recovery->estado === 'anulada', 422, 'La recuperación ya está anulada.');
            $before = $recovery->toArray();
            $recovery->fill(['estado' => 'anulada', 'cancelled_by' => $actor->id,
                'motivo' => $reason, 'version' => $recovery->version + 1])->save();
            AuditLogger::tenant($actor, 'DELETE', 'recuperacion_academica',
                (string) $recovery->id, $before, $recovery->toArray(), $reason);

            return $recovery;
        });
    }

    private function assertScope(AnoLectivo $year, Matricula $enrollment,
        AsignacionDocente $assignment, ?Periodo $period): void
    {
        abort_unless($year->estado === AnoLectivo::ESTADO_EN_CURSO, 422,
            'La recuperación requiere un año lectivo en curso.');
        abort_unless($enrollment->estado === 'activa'
            && $assignment->ano_lectivo_id === $year->id
            && $assignment->grupo_id === $enrollment->grupo_id, 422,
            'La materia, el grupo y la matrícula deben pertenecer al mismo año.');
        if ($period) {
            abort_unless($period->ano_lectivo_id === $year->id
                && $period->estado === Periodo::ESTADO_CERRADO, 422,
                'Solo puede nivelarse un período cerrado del mismo año.');
        } else {
            $periods = Periodo::where('ano_lectivo_id', $year->id)->get();
            abort_unless($periods->count() === $year->num_periodos
                && $periods->every(fn (Periodo $item) => $item->estado === Periodo::ESTADO_CERRADO), 422,
                'La habilitación anual exige todos los períodos cerrados.');
        }
    }

    private function original(Matricula $enrollment, AsignacionDocente $assignment,
        ?Periodo $period, array $config): array
    {
        if ($period) {
            $result = $this->book->subjectResult($assignment, $enrollment, $period, $config);
        } else {
            $report = $this->book->report($enrollment, applyAnnualRecoveries: false);
            $subject = collect($report['asignaturas'])->firstWhere('materia_id', $assignment->materia_id);
            $result = $subject['anual'] ?? ['estado' => 'pendiente'];
        }
        abort_unless($result['estado'] === 'calculado', 422,
            'La nota original está pendiente; no equivale a una materia reprobada.');
        abort_if($result['aprobado'], 422, 'Solo se recupera una materia reprobada.');

        return $result;
    }

    private function assertGradeInScale(string $value, array $config): void
    {
        $grade = BigRational::of($value);
        abort_if($grade->isLessThan((string) $config['valor_min'])
            || $grade->isGreaterThan((string) $config['valor_max']), 422,
            'La nota está fuera de la escala configurada.');
    }

    private function authorizeRecorder(User $actor, AsignacionDocente $assignment): void
    {
        abort_unless($actor->can('notas.gestionar_nivelaciones')
            || ($assignment->docente_id === $actor->id
                && $actor->can('notas.registrar_materia_asignada')), 403);
    }
}
