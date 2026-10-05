<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\PoliticaPromocion;
use App\Models\Academico\PromocionAcademica;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Propuesta explicable y aprobación separada; nunca matricula automáticamente en otro año. */
final class AcademicPromotionService
{
    public function __construct(private GradebookService $book) {}

    public function savePolicy(User $actor, AnoLectivo $year, int $maxFailed,
        array $mandatorySubjects, ?string $minimumAverage, int $version): PoliticaPromocion
    {
        $this->authorizeReviewer($actor);
        abort_unless($maxFailed >= 0 && $maxFailed <= 12, 422,
            'El máximo de reprobadas debe estar entre cero y doce.');

        return DB::transaction(function () use ($actor, $year, $maxFailed, $mandatorySubjects, $minimumAverage, $version) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($year->id);
            abort_unless($year->estado === AnoLectivo::ESTADO_EN_CURSO, 422,
                'Solo puede configurarse la promoción de un año en curso.');
            $config = app(SieeConfiguration::class)->resolve($year);
            $ids = array_values(array_unique(array_map('intval', $mandatorySubjects)));
            abort_unless(count($ids) === count($mandatorySubjects), 422,
                'No repitas materias obligatorias.');
            abort_unless(Materia::where('ano_lectivo_id', $year->id)->whereIn('id', $ids)->count() === count($ids), 422,
                'Las materias obligatorias deben pertenecer al año lectivo.');
            if ($minimumAverage !== null) {
                $value = BigRational::of($minimumAverage);
                abort_if($value->isLessThan((string) $config['valor_min'])
                    || $value->isGreaterThan((string) $config['valor_max']), 422,
                    'El promedio mínimo debe pertenecer a la escala SIEE.');
            }
            $policy = PoliticaPromocion::where('ano_lectivo_id', $year->id)->lockForUpdate()->first();
            abort_unless(($policy?->version ?? 0) === $version, 409,
                'La política de promoción cambió. Recarga antes de guardar.');
            $before = $policy?->toArray();
            $policy ??= new PoliticaPromocion(['ano_lectivo_id' => $year->id]);
            $policy->fill(['max_reprobadas' => $maxFailed, 'materias_obligatorias' => $ids,
                'promedio_minimo' => $minimumAverage, 'version' => $version + 1])->save();
            AuditLogger::tenant($actor, $before ? 'UPDATE' : 'CREATE', 'politica_promocion',
                (string) $policy->id, $before, $policy->toArray());

            return $policy;
        });
    }

    /** Devuelve pendiente si los insumos no están completos: jamás interpreta guiones como ceros. */
    public function proposal(AnoLectivo $year, Matricula $enrollment, ?PoliticaPromocion $policy = null): array
    {
        abort_unless($enrollment->ano_lectivo_id === $year->id, 422,
            'La matrícula no pertenece al año lectivo.');
        $policy ??= PoliticaPromocion::where('ano_lectivo_id', $year->id)->first();
        if (! $policy) {
            return ['estado' => 'pendiente', 'motivos' => ['Configura la política de promoción antes de calcular.']];
        }
        $periods = Periodo::where('ano_lectivo_id', $year->id)->get();
        if ($periods->count() !== $year->num_periodos
            || $periods->contains(fn (Periodo $period) => $period->estado !== Periodo::ESTADO_CERRADO)) {
            return ['estado' => 'pendiente', 'motivos' => ['Todos los períodos reales deben estar cerrados.']];
        }
        if (RecuperacionAcademica::where('matricula_id', $enrollment->id)
            ->where('estado', 'pendiente')->exists()) {
            return ['estado' => 'pendiente', 'motivos' => ['Hay una nivelación o habilitación sin resolver.']];
        }
        $report = $this->book->report($enrollment);
        if ($report['advertencias'] !== []) {
            return ['estado' => 'pendiente', 'motivos' => $report['advertencias']];
        }
        $config = $report['configuracion'];
        $method = MetodoAprobacion::where('ano_lectivo_id', $year->id)->findOrFail($config['metodo_id']);
        $scope = $method->ambito === MetodoAprobacion::AMBITO_AREA ? 'area' : 'materia';
        $units = $scope === 'area' ? collect($report['areas']) : collect($report['asignaturas']);
        if ($units->isEmpty()) {
            return ['estado' => 'pendiente', 'motivos' => ['No hay materias o áreas evaluables para esta matrícula.']];
        }
        $missing = $units->filter(fn ($unit) => ($unit['anual']['estado'] ?? null) !== 'calculado')
            ->pluck('nombre')->values()->all();
        $mandatory = collect($report['asignaturas'])->whereIn('materia_id', $policy->materias_obligatorias ?? []);
        if ($mandatory->count() !== count($policy->materias_obligatorias ?? [])) {
            $missing[] = 'Faltan materias obligatorias del año en el currículo del grupo.';
        }
        foreach ($mandatory as $subject) {
            if (($subject['anual']['estado'] ?? null) !== 'calculado') {
                $missing[] = 'Falta la nota anual de '.$subject['nombre'].'.';
            }
        }
        if ($missing !== []) {
            return ['estado' => 'pendiente', 'motivos' => $missing];
        }
        $failed = $units->filter(fn ($unit) => ! $unit['anual']['aprobado'])->values();
        $mandatoryFailed = $mandatory->filter(fn ($unit) => ! $unit['anual']['aprobado'])->values();
        $sum = BigRational::of(0);
        $values = $units->map(function ($unit) use (&$sum, $scope) {
            $sum = $sum->plus($unit['anual']['exact_value']);

            return ['id' => $unit[$scope.'_id'], 'nota' => $unit['anual']['exact_value'],
                'aprobado' => $unit['anual']['aprobado'],
                'periodos' => collect($unit['periodos'])->map(fn ($period) => [
                    'id' => $period['periodo_id'], 'nota' => $period['exact_value'] ?? null,
                    'origen' => $period['origen'] ?? null,
                ])->all()];
        })->sortBy('id')->values()->all();
        $average = $sum->dividedBy($units->count());
        $rounding = match ($config['redondeo'] ?? 'HALF_DOWN') {
            'HALF_DOWN' => RoundingMode::HalfDown,
            'TRUNCATE' => RoundingMode::Down,
            default => RoundingMode::HalfUp,
        };
        $displayAverage = (string) $average->toScale((int) $config['decimales'], $rounding);
        $publishedAverage = BigRational::of($displayAverage);
        $averageOk = $policy->promedio_minimo === null
            || $publishedAverage->isGreaterThanOrEqualTo($policy->promedio_minimo);
        $generalOk = $method->ambito !== MetodoAprobacion::AMBITO_PROMEDIO_GENERAL
            || $publishedAverage->isGreaterThanOrEqualTo((string) $config['nota_minima']);
        $promoted = $failed->count() <= $policy->max_reprobadas
            && $mandatoryFailed->isEmpty() && $averageOk && $generalOk;
        $snapshot = [
            'ano_lectivo_id' => $year->id, 'matricula_id' => $enrollment->id,
            'politica_version' => $policy->version, 'ambito' => $method->ambito,
            'escala_id' => $config['escala_id'], 'metodo_id' => $method->id,
            'siee' => $year->siee,
            'periodos' => $periods->sortBy('orden')->map(fn ($period) => [
                'id' => $period->id, 'peso' => $period->peso,
            ])->values()->all(),
            'max_reprobadas' => $policy->max_reprobadas,
            'materias_obligatorias' => $policy->materias_obligatorias ?? [],
            'promedio_minimo' => $policy->promedio_minimo,
            'resultados' => $values,
        ];

        return ['estado' => 'calculada', 'resultado' => $promoted ? 'promovido' : 'reprobado',
            'ambito' => $method->ambito, 'reprobadas' => $failed->pluck($scope.'_id')->all(),
            'obligatorias_reprobadas' => $mandatoryFailed->pluck('materia_id')->all(),
            'promedio_exacto' => (string) $average->simplified(),
            'promedio' => $displayAverage,
            'promedio_cumple' => $averageOk && $generalOk,
            'huella' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'insumos' => $snapshot];
    }

    public function approve(User $actor, AnoLectivo $year, Matricula $enrollment,
        string $fingerprint, string $result, ?Grado $targetGrade, string $reason,
        int $version): PromocionAcademica
    {
        $this->authorizeReviewer($actor);

        return DB::transaction(function () use ($actor, $year, $enrollment, $fingerprint, $result, $targetGrade, $reason, $version) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($year->id);
            abort_unless($year->estado === AnoLectivo::ESTADO_EN_CURSO, 422,
                'El año lectivo debe estar en curso para revisar promociones.');
            $enrollment = Matricula::with('grupo.grado')->lockForUpdate()->findOrFail($enrollment->id);
            abort_unless($enrollment->estado === 'activa', 422,
                'Solo se decide promoción de matrículas activas.');
            $proposal = $this->proposal($year, $enrollment);
            abort_unless($proposal['estado'] === 'calculada', 422,
                'Las notas y recuperaciones deben estar completas antes de aprobar promoción.');
            abort_unless(hash_equals($proposal['huella'], $fingerprint), 409,
                'Las notas o reglas cambiaron. Actualiza la propuesta antes de aprobar.');
            abort_unless($result === $proposal['resultado']
                || ($result === 'egresado' && $proposal['resultado'] === 'promovido'), 422,
                'Una decisión distinta de la propuesta requiere el proceso de consejo académico.');
            if ($result === 'promovido') {
                abort_unless($targetGrade && $targetGrade->ano_lectivo_id === $year->id
                    && $targetGrade->id !== $enrollment->grupo->grado_id, 422,
                    'Selecciona explícitamente el grado destino de este mismo año como referencia.');
            } else {
                abort_if($targetGrade !== null, 422,
                    'Reprobación o egreso no deben tener un grado destino distinto.');
            }
            $decision = PromocionAcademica::where('matricula_id', $enrollment->id)->lockForUpdate()->first();
            abort_unless(($decision?->version ?? 0) === $version, 409,
                'Otra persona revisó la promoción. Recarga antes de aprobar.');
            $before = $decision?->toArray();
            $decision ??= new PromocionAcademica(['matricula_id' => $enrollment->id]);
            $decision->fill([
                'ano_lectivo_id' => $year->id, 'propuesta' => $proposal['resultado'],
                'resultado' => $result, 'grado_destino_id' => $targetGrade?->id,
                'insumos' => $proposal['insumos'], 'huella' => $proposal['huella'],
                'motivo' => $reason, 'reviewed_by' => $actor->id,
                'approved_at' => now(), 'version' => $version + 1,
            ])->save();
            AuditLogger::tenant($actor, $before ? 'UPDATE' : 'CREATE', 'promocion_academica',
                (string) $decision->id, $before, $decision->toArray(), $reason);

            return $decision;
        });
    }

    public function isCurrent(AnoLectivo $year, Matricula $enrollment,
        PromocionAcademica $decision): bool
    {
        $proposal = $this->proposal($year, $enrollment);

        return $proposal['estado'] === 'calculada'
            && hash_equals($proposal['huella'], $decision->huella)
            && $proposal['resultado'] === $decision->propuesta;
    }

    private function authorizeReviewer(User $actor): void
    {
        abort_unless($actor->can('academico.anos.transicionar')
            && ($actor->hasRole('rector') || $actor->esSuperadminPlataforma()), 403);
    }
}
