<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\PoliticaPromocion;
use App\Models\Academico\PromocionAcademica;
use App\Models\Academico\RecuperacionAcademica;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Diagnóstico de solo lectura: no promueve ni modifica matrículas o notas. */
final class AcademicYearReviewService
{
    public function __construct(private AcademicPromotionService $promotions) {}

    /** @return array<string, mixed> */
    public function review(AnoLectivo $year, ?Collection $periods = null): array
    {
        $periods ??= $year->periodos()->get();
        $issues = [];
        if ($year->estado !== AnoLectivo::ESTADO_EN_CURSO) {
            $issues[] = 'year_not_in_progress';
        }
        if ($periods->count() !== $year->num_periodos) {
            $issues[] = 'period_count';
        }

        $previous = null;
        foreach ($periods as $index => $period) {
            if ($period->orden !== $index + 1) {
                $issues[] = 'period_order';
            }
            if ($period->fecha_inicio->gt($period->fecha_fin)
                || $period->fecha_inicio->lt($year->fecha_inicio)
                || $period->fecha_fin->gt($year->fecha_fin)) {
                $issues[] = 'period_out_of_year';
            }
            if ($previous === null && ! $period->fecha_inicio->isSameDay($year->fecha_inicio)) {
                $issues[] = 'first_day_uncovered';
            }
            if ($previous !== null && ! $period->fecha_inicio->isSameDay($previous->fecha_fin->copy()->addDay())) {
                $issues[] = 'period_gap_or_overlap';
            }
            $previous = $period;
        }
        if ($previous === null || ! $previous->fecha_fin->isSameDay($year->fecha_fin)) {
            $issues[] = 'last_day_uncovered';
        }
        if (($year->siee['modo_anual'] ?? null) === 'WEIGHTED_AVERAGE') {
            try {
                GradeCalculationService::assertWeights($periods->pluck('peso')->all());
            } catch (ValidationException) {
                $issues[] = 'period_weights';
            }
        }

        $closed = $periods->where('estado', Periodo::ESTADO_CERRADO)->count();
        if ($closed !== $periods->count()) {
            $issues[] = 'periods_not_closed';
        }

        // Incluso una matrícula retirada demuestra operación escolar e histórico.
        $enrollments = Matricula::where('ano_lectivo_id', $year->id)->count();
        $active = Matricula::where('ano_lectivo_id', $year->id)->where('estado', 'activa')->get();
        $approved = 0;
        if ($enrollments > 0 && RecuperacionAcademica::where('ano_lectivo_id', $year->id)
            ->where('estado', 'pendiente')->exists()) {
            $issues[] = 'recovery_pending';
        }
        if ($active->isNotEmpty()) {
            $policy = PoliticaPromocion::where('ano_lectivo_id', $year->id)->first();
            if (! $policy) {
                $issues[] = 'promotion_policy_missing';
            } elseif ($closed === $periods->count() && $periods->count() === $year->num_periodos) {
                $decisions = PromocionAcademica::where('ano_lectivo_id', $year->id)
                    ->whereIn('matricula_id', $active->modelKeys())->get()->keyBy('matricula_id');
                foreach ($active as $enrollment) {
                    $decision = $decisions->get($enrollment->id);
                    if (! $decision) {
                        $issues[] = 'promotion_pending';
                    } elseif (! $this->promotions->isCurrent($year, $enrollment, $decision)) {
                        $issues[] = 'promotion_outdated';
                    } else {
                        $approved++;
                    }
                }
            } else {
                $issues[] = 'promotion_pending';
            }
        }

        $issues = array_values(array_unique($issues));

        return [
            'ano_token' => \App\Support\OpaqueUrlToken::for('ano-lectivo', $year->id),
            'periodos_esperados' => $year->num_periodos,
            'periodos_configurados' => $periods->count(),
            'periodos_cerrados' => $closed,
            'matriculas' => $enrollments,
            'matriculas_activas' => $active->count(),
            'promociones_aprobadas' => $approved,
            'sin_matriculas' => $enrollments === 0,
            'solo_retiradas' => $enrollments > 0 && $active->isEmpty(),
            'bloqueos' => $issues,
            'puede_cerrar' => $issues === [],
        ];
    }
}
