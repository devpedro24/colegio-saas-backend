<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Matricula;
use App\Models\User;
use App\Services\Ingreso\EntryAssessments;
use App\Services\Ingreso\GradeSourceDecisions;
use App\Services\Ingreso\PendingCertificates;
use App\Services\Ingreso\GradeTransferResults;
use App\Services\Ingreso\SplitPeriodGrades;
use App\Services\Ingreso\SplitPeriodSupports;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One approved equivalence per enrollment, destination subject and real period. */
final class RecognizedPeriodGrades
{
    private function __construct(private Collection $results) {}

    public static function approved(iterable $enrollments, ?int $subject = null, ?int $period = null): Builder
    {
        $ids = collect($enrollments);
        $source = fn (string $table, string $origin) => DB::table($table)
            ->select('id', 'matricula_id', 'materia_id', 'periodo_id', 'token', 'decision', 'version')
            ->selectRaw($origin === 'externa' ? 'definitiva_planilla' : 'NULL AS definitiva_planilla')->selectRaw('? AS origen', [$origin])
            ->whereIn('matricula_id', $ids)
            ->whereNotNull('aprobado_en')->whereNotNull('decision')
            ->when($subject !== null, fn ($q) => $q->where('materia_id', $subject))
            ->when($period !== null, fn ($q) => $q->where('periodo_id', $period));
        return DB::query()->fromSub($source('matricula_definitivas_externas', 'externa')
            ->unionAll($source(EntryAssessments::TABLE, 'valoracion')), 'aprobadas');
    }

    public static function forEnrollments(iterable $enrollments, ?int $subject = null, ?int $period = null, ?int $group = null): self
    {
        $enrollments = collect($enrollments);
        $transfers = GradeTransferResults::active($enrollments, $subject, $period)
            ->when($group !== null, fn ($q) => $q->where('m.grupo_destino_id', $group))->get()->mapWithKeys(function ($row) {
                $grade = $row->resultado ? json_decode($row->resultado, true, flags: JSON_THROW_ON_ERROR) : null;
                return [self::key($row->matricula_id, $row->materia_id, $row->periodo_id) => $grade
                    ? [...$grade, 'origen' => 'traslado', 'traslado_token' => $row->token]
                    : ['estado' => 'pendiente', 'origen' => 'pendiente_traslado', 'motivo' => $row->motivo]];
            });
        $cases = SplitPeriodGrades::active($enrollments, $subject, $period)->join('matriculas as e', 'e.id', '=', 'matricula_id')
            ->select(SplitPeriodGrades::TABLE.'.*', 'e.grupo_id')->get();
        $supports = SplitPeriodSupports::load($cases);
        $splits = $cases->mapWithKeys(function ($row) use ($group, $supports) {
                $decision = $row->decision ? json_decode($row->decision, true, flags: JSON_THROW_ON_ERROR) : null;
                $intervals = SplitPeriodGrades::datesFor($row, $group ?? $row->grupo_id);
                $result = $decision ? [...$decision['resultado'], 'estado' => 'calculado', 'origen' => 'consolidacion',
                    'consolidacion_token' => $row->token, 'categoria_reconocida' => null]
                    : ['estado' => 'pendiente', 'origen' => 'pendiente_consolidacion', 'motivo' => 'Pendiente de consolidar los tramos del periodo.'];
                return [self::key($row->matricula_id, $row->materia_id, $row->periodo_id) => [...$result, 'intervalos_grupo' => $intervals,
                    'actividades_apoyo' => SplitPeriodSupports::forGroup($supports, $row, $group ?? $row->grupo_id)
                        ->map(fn ($a) => SplitPeriodSupports::sheet($a))->values()->all()]];
            });
        $pending = PendingCertificates::open($enrollments, $subject, $period)->get()->mapWithKeys(fn ($row) =>
            [self::key($row->matricula_id, $row->materia_id, $row->periodo_id) => $row->certificado_revisado_id !== null
                ? ['estado' => 'pendiente', 'origen' => 'sin_equivalencia', 'motivo' => $row->motivo_sin_equivalencia]
                : ['estado' => 'pendiente',
                'origen' => 'pendiente_certificado', 'certificado_recibido' => $row->resultado_id !== null, 'motivo' => $row->resultado_id
                    ? 'Certificado recibido. Falta aprobar la equivalencia.' : 'Pendiente de certificado del colegio anterior.']]);
        $rows = self::approved($enrollments, $subject, $period)->get();
        $choices = GradeSourceDecisions::comparisons($rows);
        $effective = $rows->mapWithKeys(fn ($r) => [
            self::key($r->matricula_id, $r->materia_id, $r->periodo_id) => self::approvedResult($r),
        ]);
        foreach ($rows as $row) {
            if ($choices->has($row->token)) {
                $effective->put(self::key($row->matricula_id, $row->materia_id, $row->periodo_id), $choices[$row->token]['resultado']);
            }
        }
        return new self($transfers->replace($splits)->replace($pending)->replace($effective));
    }

    public static function approvedResult(object $row): array
    {
        $decision = json_decode($row->decision, true, flags: JSON_THROW_ON_ERROR);
        $scale = $decision['siee'];
        $result = app(GradeCalculationService::class)->result(['mode' => 'MANUAL', 'manual' => $decision['valor']], [
            ...$scale['reglas'], 'decimales' => $scale['decimales'], 'nota_minima' => $scale['metodo']['nota_minima'],
        ]);
        return [...$result, 'estado' => 'calculado', 'origen' => $row->origen,
            ($row->origen === 'externa' ? 'equivalencia_token' : 'valoracion_token') => $row->token,
            'categoria_reconocida' => $decision['categoria'], 'version_siee_origen' => $scale['version']];
    }

    private static function key(int $enrollment, int $subject, int $period): string
    {
        return $enrollment.':'.$subject.':'.$period;
    }

    public function get(int $enrollment, int $subject, int $period): ?array
    {
        return $this->results->get(self::key($enrollment, $subject, $period));
    }

    public static function studentSummary(User $student): Collection
    {
        $enrollments = Matricula::conAccesoEstudiantil()->where('estudiante_id', $student->id)->pluck('id');
        if ($enrollments->isEmpty()) {
            return collect();
        }
        $results = self::forEnrollments($enrollments)->results;
        // An entry assessment can await approval even without a certificate or a group movement.
        $awaiting = DB::table(EntryAssessments::TABLE)->whereIn('matricula_id', $enrollments)
            ->whereNotNull('fecha_ingreso')->whereNull('resultado_traslado_id')->whereNull('aprobado_en')
            ->get(['matricula_id', 'materia_id', 'periodo_id']);
        foreach ($awaiting as $row) {
            $key = self::key($row->matricula_id, $row->materia_id, $row->periodo_id);
            if (!$results->has($key)) {
                $results->put($key, ['estado' => 'pendiente', 'origen' => 'pendiente_valoracion']);
            }
        }

        $rows = $results->map(function (array $grade, string $key) {
            [, $subject, $period] = explode(':', $key);
            return ['token' => OpaqueUrlToken::for('resultado-perfil', $key), 'subject' => $subject, 'period' => $period,
                'estado' => $grade['estado'], 'origen' => ($grade['certificado_recibido'] ?? false) ? 'pendiente_equivalencia' : $grade['origen'],
                'nota' => $grade['estado'] === 'calculado' ? ($grade['categoria_reconocida'] ?? $grade['display_value']) : null];
        });
        $subjects = DB::table('materias')->whereIn('id', $rows->pluck('subject'))->pluck('nombre', 'id');
        $periods = DB::table('periodos as p')->join('anos_lectivos as a', 'a.id', '=', 'p.ano_lectivo_id')
            ->whereIn('p.id', $rows->pluck('period'))->get(['p.id', 'p.nombre', 'p.orden', 'a.nombre as ano', 'a.fecha_inicio'])->keyBy('id');

        return $rows->map(function ($row) use ($subjects, $periods) {
            $period = $periods[$row['period']];
            return ['token' => $row['token'], 'ano' => $period->ano, 'materia' => $subjects[$row['subject']], 'periodo' => $period->nombre,
                'origen' => $row['origen'], 'estado' => $row['estado'], 'nota' => $row['nota'], 'inicio' => $period->fecha_inicio, 'orden' => $period->orden];
        })->sortBy([['inicio', 'desc'], ['materia', 'asc'], ['orden', 'asc']])->values()
            ->map(fn ($row) => \Illuminate\Support\Arr::except($row, ['inicio', 'orden']));
    }

    public static function protectedEnrollments(iterable $enrollments, int $subject, int $period): Collection
    {
        return self::approved($enrollments, $subject, $period)->pluck('matricula_id')
            ->merge(PendingCertificates::open($enrollments, $subject, $period)->pluck('p.matricula_id'))
            ->merge(SplitPeriodGrades::active($enrollments, $subject, $period)->whereNotNull('decision')->pluck('matricula_id'))
            ->merge(GradeTransferResults::active($enrollments, $subject, $period)->pluck('m.matricula_id'))->unique()->values();
    }

    public static function requireLocalGrades(iterable $enrollments, int $subject, int $period): void
    {
        $pending = PendingCertificates::open($enrollments, $subject, $period)->first();
        abort_if($pending?->certificado_revisado_id !== null, 422, 'Registra y aprueba la valoración de esta materia desde la ficha de matrícula.');
        abort_if($pending, 422,
            'La definitiva está pendiente del certificado y de la aprobación de su equivalencia.');
        $origin = self::approved($enrollments, $subject, $period)->value('origen');
        $splits = SplitPeriodGrades::active($enrollments, $subject, $period)->join('matriculas as e', 'e.id', '=', 'matricula_id')
            ->get(['decision', 'tramos', 'e.grupo_id']);
        foreach ($splits as $split) {
            abort_if($split->decision !== null, 422, 'La definitiva del periodo compartido ya fue aprobada.');
            abort_if(SplitPeriodGrades::datesFor($split, $split->grupo_id) === [], 422, 'El estudiante cursó este periodo en otros grupos.');
        }
        abort_if($origin === 'valoracion', 422, 'El estudiante ya tiene una valoración institucional aprobada para este periodo.');
        abort_if($origin === null && GradeTransferResults::active($enrollments, $subject, $period)->exists(), 422,
            'El periodo corresponde al grupo anterior. Consulta la definitiva o registra una valoración en la ficha de matrícula.');
        abort_if($origin === 'externa', 422,
            'La planilla contiene una definitiva externa aprobada. Las actividades de ese periodo no aplican al estudiante.');
    }
}
