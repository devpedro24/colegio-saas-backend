<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\GradeCalculationService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigRational;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SplitPeriodGrades
{
    public const TABLE = 'matricula_consolidaciones_periodo';

    public static function active(iterable $ids, ?int $subject = null, ?int $period = null): Builder
    {
        return DB::table(self::TABLE)->whereIn('matricula_id', collect($ids))->whereNull('sustituido_en')
            ->when($subject !== null, fn ($q) => $q->where('materia_id', $subject))
            ->when($period !== null, fn ($q) => $q->where('periodo_id', $period));
    }

    /** Inside the placement transaction, after inserting the movement. */
    public function moved(Matricula $student, Grupo $destination, int $movement, string $date): void
    {
        $period = Periodo::where('ano_lectivo_id', $student->ano_lectivo_id)->whereDate('fecha_inicio', '<', $date)
            ->whereDate('fecha_fin', '>=', $date)->first();
        if (! $period) {
            return;
        }
        $entry = app(EnrollmentGradeContext::class)->entryDate($student)
            ?? $student->created_at?->timezone(tenant()->zonaHorariaInstitucional())->toDateString();
        $start = max($period->fecha_inicio->toDateString(), $entry ?? '');
        if ($start >= $date) {
            return;
        }
        $end = $period->fecha_fin->toDateString();
        $moves = DB::table('matricula_movimientos')->where('matricula_id', $student->id)
            ->whereBetween('fecha_efectiva', [$start, $end])->orderBy('version')->get();
        $spans = [];
        foreach ($moves as $move) {
            if ($move->fecha_efectiva > $start) {
                $spans[] = ['grupo_id' => $move->grupo_origen_id, 'grupo' => $move->origen, 'desde' => $start,
                    'hasta' => \Carbon\CarbonImmutable::parse($move->fecha_efectiva)->subDay()->toDateString()];
            }
            $start = $move->fecha_efectiva;
        }
        $spans[] = ['grupo_id' => $destination->id, 'grupo' => $moves->last()->destino, 'desde' => $start, 'hasta' => $end];
        self::active([$student->id], period: $period->id)->update(['sustituido_en' => now()]);
        $groups = collect($spans)->pluck('grupo_id')->unique()->values();
        if ($groups->count() < 2) {
            return;
        }
        $subjects = DB::table('asignaciones_docentes')->where('ano_lectivo_id', $student->ano_lectivo_id)
            ->whereIn('grupo_id', $groups)->select('grupo_id', 'materia_id')
            ->union(DB::table('materias_curriculares as m')->join('grupos as g', 'g.grado_id', '=', 'm.grado_id')
                ->where('m.ano_lectivo_id', $student->ano_lectivo_id)->whereIn('g.id', $groups)->select('g.id as grupo_id', 'm.materia_id'))
            ->get()->groupBy('materia_id');
        $recognized = DB::table(CertifiedExternalGrades::RESULTS)->where('matricula_id', $student->id)->where('periodo_id', $period->id)
            ->whereNotNull('aprobado_en')->pluck('materia_id')->merge(DB::table(EntryAssessments::TABLE)->where('matricula_id', $student->id)
                ->where('periodo_id', $period->id)->whereNotNull('aprobado_en')->pluck('materia_id'));
        foreach ($subjects->except($recognized->all()) as $subject => $members) {
            if ($members->count() === $groups->count()) {
                DB::table(self::TABLE)->insert(['token' => Str::random(24), 'matricula_id' => $student->id, 'materia_id' => $subject,
                    'periodo_id' => $period->id, 'movimiento_id' => $movement, 'tramos' => json_encode($spans, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public static function datesFor(object $row, int $group): array
    {
        $spans = json_decode($row->tramos, true, flags: JSON_THROW_ON_ERROR);
        return array_values(array_map(fn ($s) => Arr::only($s, ['desde','hasta']), array_filter($spans, fn ($s) => $s['grupo_id'] === $group)));
    }

    public static function outsideDates(AsignacionDocente $assignment, int $period, array $activityDates, array $rows): array
    {
        $cases = self::active(array_unique(array_column($rows, 'matricula_id')), $assignment->materia_id, $period)->get()->keyBy('matricula_id');
        $loaded = SplitPeriodSupports::load($cases);
        $supports = $cases->map(fn ($case) => SplitPeriodSupports::forGroup($loaded, $case, $assignment->grupo_id));
        $intervals = $cases->map(fn ($case) => self::datesFor($case, $assignment->grupo_id));
        $excluded = [];
        foreach ($rows as $row) {
            if ($supports->get($row['matricula_id'])?->has($row['actividad_id'])) {
                continue;
            }
            $spans = $intervals->get($row['matricula_id']);
            $date = $activityDates[$row['actividad_id']] ?? null;
            if ($spans !== null && !collect($spans)->contains(fn ($span) => $date !== null && $span['desde'] <= $date && $date <= $span['hasta'])) {
                $excluded[] = $row['matricula_id'];
            }
        }
        return $excluded;
    }

    private function evidence(Matricula $student, object $case, array $config, bool $includeSupports = true, ?array &$candidates = null): array
    {
        $loaded = $includeSupports ? SplitPeriodSupports::load(collect([$case])) : collect();
        $period = Periodo::findOrFail($case->periodo_id);
        $book = app(GradebookService::class);
        $config = $book->periodConfig($config, $period);
        $spans = json_decode($case->tramos, true, flags: JSON_THROW_ON_ERROR);
        $parts = [];
        foreach ($spans as $index => $span) {
            $assignment = AsignacionDocente::where('ano_lectivo_id', $student->ano_lectivo_id)
                ->where('grupo_id', $span['grupo_id'])->where('materia_id', $case->materia_id)->first();
            $components = $assignment ? ComponenteEvaluacion::with('actividades')->where('asignacion_id', $assignment->id)
                ->where('periodo_id', $period->id)->orderBy('id')->get() : collect();
            $activities = $components->flatMap->actividades->keyBy('id');
            $grades = Calificacion::where('matricula_id', $student->id)->whereIn('actividad_id', $activities->keys())->get()->keyBy('actividad_id');
            $manuals = $book->componentManuals($components->pluck('id')->all(), collect([$student->id]))->get($student->id, collect());
            $manual = $assignment && $config['modo_asignatura'] === 'MANUAL' ? DB::table('resultados_manuales_materia')
                ->where('asignacion_id', $assignment->id)->where('matricula_id', $student->id)->where('periodo_id', $period->id)->first() : null;
            $tree = $book->resultFromLoaded($components, $grades, $config, $manual, $manuals)['trace'] ?? ['mode' => 'MANUAL', 'manual' => null];
            $supports = SplitPeriodSupports::forGroup($loaded, $case, $span['grupo_id']);
            if ($candidates !== null && $index === array_key_last($spans) && $span['grupo_id'] === $student->grupo_id && $period->estado === 'abierto') {
                $intervals = self::datesFor($case, $span['grupo_id']);
                foreach ($this->activityIds($tree) as $id) {
                    $activity = $activities->get($id);
                    $date = $activity?->fecha;
                    if ($date === null || $date < $period->fecha_inicio->toDateString() || $date >= $span['desde']
                        || $supports->has($id) || collect($intervals)->contains(fn ($s) => $s['desde'] <= $date && $date <= $s['hasta'])) {
                        continue;
                    }
                    $candidates[$id] = ['actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $id), 'nombre' => $activity->nombre, 'fecha' => $date];
                }
            }
            $span['apoyos'] = $supports->where('tramo_desde', $span['desde']);
            $items = [];
            $partial = $this->subtotal($tree, $activities, $span, [], $items);
            $parts[] = [...Arr::only($span, ['grupo','desde','hasta']), 'evidencias' => $items, 'exacta' => $partial === null ? null : (string) $partial->simplified(),
                'subtotal' => $partial === null ? null : app(GradeCalculationService::class)->result(['mode' => 'MANUAL', 'manual' => (string) $partial], $config)['display_value']];
        }
        return $parts;
    }

    private function activityIds(array $node): \Generator
    {
        foreach ($node['inputs'] ?? [] as $input) {
            if (isset($input['node'])) {
                yield from $this->activityIds($input['node']);
            } elseif (str_starts_with($input['reference'] ?? '', 'actividad:')) {
                yield (int) substr($input['reference'], strlen('actividad:'));
            }
        }
    }

    /** Apply the gradebook tree to the applicable activities only. Renormalize at each weighted level.
     * A missing applicable grade keeps that whole segment pending. */
    private function subtotal(array $node, Collection $activities, array $span, array $path, array &$items): ?BigRational
    {
        if ($node['mode'] === 'MANUAL') {
            $value = $node['manual'] ?? null;
            $items[] = ['nombre' => 'Resultado manual', 'fecha' => null, 'valor' => $value, 'peso' => null, 'componentes' => $path];
            return $value === null ? null : BigRational::of((string) $value);
        }
        $sum = BigRational::zero();
        $total = BigRational::zero();
        $incomplete = false;
        foreach ($node['inputs'] as $input) {
            $weight = $node['mode'] === 'WEIGHTED_AVERAGE' ? $input['weight'] : 1;
            if (isset($input['node'])) {
                $before = count($items);
                $value = $this->subtotal($input['node'], $activities, $span,
                    [...$path, ['nombre' => $input['label'], 'peso' => $node['mode'] === 'WEIGHTED_AVERAGE' ? $weight : null]], $items);
                if (count($items) === $before) {
                    continue;
                }
            } else {
                $activity = $activities->get((int) substr($input['reference'], strlen('actividad:')));
                $date = $activity?->fecha;
                $support = $span['apoyos']->get($activity?->id);
                if ($date === null || (! $support && ($date < $span['desde'] || $date > $span['hasta']))) {
                    continue;
                }
                $items[] = ['nombre' => $activity->nombre, 'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
                    ...($support ? ['apoyo' => Arr::only((array) $support, ['fecha_entrega','motivo','responsable','asignado_en'])] : []),
                    'fecha' => $date, 'valor' => $input['value'], 'peso' => $node['mode'] === 'WEIGHTED_AVERAGE' ? $weight : null, 'componentes' => $path];
                $value = $input['value'] === null ? null : BigRational::of((string) $input['value']);
            }
            if ($value === null || $weight === null || BigRational::of((string) $weight)->isNegative()) {
                $incomplete = true;
                continue;
            }
            $sum = $sum->plus($value->multipliedBy((string) $weight));
            $total = $total->plus((string) $weight);
        }
        return $incomplete || $total->isZero() ? null : $sum->dividedBy($total);
    }

    public function details(Matricula $student): Collection
    {
        $cases = self::active([$student->id])->join('materias as s', 's.id', '=', self::TABLE.'.materia_id')
            ->join('periodos as p', 'p.id', '=', self::TABLE.'.periodo_id')
            ->select(self::TABLE.'.*', 's.nombre as materia', 'p.nombre as periodo')->orderBy('p.orden')->orderBy('s.nombre')->get();
        return $cases->map(function ($case) use ($student) {
            $decision = $case->decision ? json_decode($case->decision, true, flags: JSON_THROW_ON_ERROR) : null;
            $candidates = [];
            $basis = $decision ?? $this->basis($student, $case, candidates: $candidates);
            return [...Arr::only((array) $case, ['token','materia','periodo']), 'tramos' => $basis['tramos'],
                'actividades_disponibles' => array_values($candidates), 'version' => $basis['version'], 'aprobacion' => $decision ? Arr::only($decision, ['pesos','motivo','responsable','fecha','definitiva']) : null];
        });
    }

    private function basis(Matricula $student, object $case, bool $includeSupports = true, ?array &$candidates = null): array
    {
        $scale = app(EnrollmentGradeContext::class)->scale($student);
        $config = app(SieeConfiguration::class)->resolve($student->anoLectivo);
        $parts = $this->evidence($student, $case, $config, $includeSupports, $candidates);
        return ['tramos' => $parts, 'version' => hash('sha256', json_encode([$case->token, $scale['version'], $parts], JSON_THROW_ON_ERROR)), 'config' => $config];
    }

    private function combine(array $basis, array $weights): array
    {
        abort_unless(count($weights) === count($basis['tramos']), 422, 'Indica el porcentaje de cada tramo.');
        GradeCalculationService::assertWeights($weights);
        $inputs = [];
        foreach ($basis['tramos'] as $i => $part) {
            abort_if($part['exacta'] === null, 422, 'Cada tramo necesita sus notas antes de aprobar.');
            $inputs[] = ['value' => $part['exacta'], 'weight' => $weights[$i]];
        }
        return app(GradeCalculationService::class)->result(['mode' => 'WEIGHTED_AVERAGE', 'inputs' => $inputs], $basis['config']);
    }

    public function preview(Matricula $student, string $token, array $data): array
    {
        $case = self::active([$student->id])->where('token', $token)->firstOrFail();
        abort_if($case->decision, 409, 'La definitiva ya fue aprobada.');
        $basis = $this->basis($student, $case);
        abort_unless(hash_equals($basis['version'], $data['version']), 409, 'Cambió la evidencia. Actualiza la ficha.');
        $result = ['definitiva' => $this->combine($basis, $data['pesos'])['display_value']];
        if (collect($basis['tramos'])->flatMap(fn ($part) => $part['evidencias'])->contains(fn ($item) => isset($item['apoyo']))) {
            $baseline = $this->basis($student, $case, false);
            $result['sin_apoyos'] = collect($baseline['tramos'])->contains(fn ($part) => $part['exacta'] === null)
                ? null : $this->combine($baseline, $data['pesos'])['display_value'];
        }
        return $result;
    }

    public function assignSupport(Matricula $student, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($student, $token, $data, $actor) {
            $student = app(EnrollmentGradeContext::class)->lockEnrollment($student);
            $case = self::active([$student->id])->where('token', $token)->firstOrFail();
            abort_if($case->decision, 409, 'La definitiva ya fue aprobada.');
            $period = Periodo::findOrFail($case->periodo_id);
            abort_unless($period->estado === 'abierto', 422, 'El periodo debe estar abierto para asignar el apoyo.');
            $spans = json_decode($case->tramos, true, flags: JSON_THROW_ON_ERROR);
            $target = end($spans);
            abort_unless($student->grupo_id === $target['grupo_id'], 409, 'El grupo actual de la matrícula cambió.');
            $existing = SplitPeriodSupports::forGroup(SplitPeriodSupports::load(collect([$case])), $case, $student->grupo_id)
                ->first(fn ($a) => OpaqueUrlToken::for('actividad-evaluacion', $a->actividad_id) === $data['actividad_token']);
            if ($existing) {
                abort_unless($existing->fecha_entrega === $data['fecha_entrega'] && $existing->motivo === $data['motivo'], 409, 'La actividad ya tiene un apoyo asignado.');
                return;
            }
            abort_if($data['fecha_entrega'] < $target['desde'], 422, 'La entrega debe ser posterior o igual a la fecha del traslado.');
            $candidates = [];
            $this->basis($student, $case, candidates: $candidates);
            $id = array_search($data['actividad_token'], array_map(fn ($a) => $a['actividad_token'], $candidates), true);
            abort_if($id === false, 404, 'La actividad no está disponible para este traslado.');
            abort_if(DB::table(SplitPeriodSupports::TABLE)->where('matricula_id', $student->id)->where('actividad_id', $id)->exists(),
                409, 'La actividad ya fue asignada en otro tramo.');
            DB::table(SplitPeriodSupports::TABLE)->insert(['consolidacion_id' => $case->id, 'matricula_id' => $student->id,
                'actividad_id' => $id, 'tramo_desde' => $target['desde'], 'fecha_entrega' => $data['fecha_entrega'],
                'motivo' => $data['motivo'], 'asignado_por_id' => $actor->id, 'responsable' => $actor->name, 'asignado_en' => now()]);
            AuditLogger::tenant($actor, 'CREATE', 'matricula_actividad_apoyo', (string) $student->id, null,
                ['consolidacion' => $token, ...Arr::only($data, ['actividad_token','fecha_entrega'])], $data['motivo']);
        });
    }

    public function approve(Matricula $student, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($student, $token, $data, $actor) {
            $locked = app(EnrollmentGradeContext::class)->lockEnrollment($student);
            $case = self::active([$student->id])->where('token', $token)->firstOrFail();
            $weights = array_map(fn ($weight) => (string) \Brick\Math\BigDecimal::of((string) $weight)->strippedOfTrailingZeros(), $data['pesos']);
            $fingerprint = hash('sha256', json_encode([$data['version'], $weights, $data['motivo']], JSON_THROW_ON_ERROR));
            if ($case->decision) {
                abort_unless(json_decode($case->decision, true)['huella'] === $fingerprint, 409, 'La definitiva ya fue aprobada.');
                return;
            }
            $basis = $this->basis($locked, $case);
            abort_unless(hash_equals($basis['version'], $data['version']), 409, 'Cambió la evidencia o la escala. Revisa los tramos actualizados.');
            $grade = $this->combine($basis, $weights);
            $decision = [...Arr::except($basis, ['config']), 'resultado' => $grade, 'reglas' => Arr::only($basis['config'], ['redondeo','decimales','nota_minima']),
                'pesos' => $weights, 'definitiva' => $grade['display_value'], 'motivo' => $data['motivo'],
                'fecha' => now()->toIso8601String(), 'responsable' => $actor->name, 'huella' => $fingerprint];
            DB::table(self::TABLE)->where('id', $case->id)->update(['decision' => json_encode($decision, JSON_THROW_ON_ERROR)]);
            AuditLogger::tenant($actor, 'APPROVE', 'matricula_consolidacion_periodo', (string) $locked->id, null,
                ['consolidacion' => $case->token, 'definitiva' => $grade['display_value'], 'pesos' => $weights], $data['motivo']);
        });
    }
}
