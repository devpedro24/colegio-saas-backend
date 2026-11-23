<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Services\GradebookService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GradeTransferResults
{
    public const TABLE = 'matricula_resultados_traslado';

    public static function active(iterable $enrollments, ?int $subject = null, ?int $period = null): Builder
    {
        return DB::table(self::TABLE.' as t')->join('matricula_movimientos as m', 'm.id', '=', 't.movimiento_id')
            ->join('matriculas as e', 'e.id', '=', 'm.matricula_id')
            ->whereIn('m.matricula_id', collect($enrollments))->whereColumn('e.grupo_id', 'm.grupo_destino_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('matricula_movimientos as posterior')
                ->whereColumn('posterior.matricula_id', 'm.matricula_id')->whereColumn('posterior.version', '>', 'm.version'))
            ->when($subject !== null, fn ($q) => $q->where('t.materia_id', $subject))
            ->when($period !== null, fn ($q) => $q->where('t.periodo_id', $period))
            ->select('t.*', 'm.matricula_id', 'm.fecha_efectiva');
    }

    private function sourceSubjects(Matricula $enrollment, int $groupId): Collection
    {
        $source = clone $enrollment;
        $source->grupo_id = $groupId;
        $source->setRelation('grupo', Grupo::withTrashed()->with(['grado' => fn ($q) => $q->withTrashed()])->findOrFail($groupId));
        $siee = $source->anoLectivo->siee;
        if (empty($siee['escala_id']) || empty($siee['metodo_id'])) {
            // Placement is possible before the school has configured how to calculate finals.
            $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $source->ano_lectivo_id)
                ->where('grado_id', $source->grupo->grado_id)->pluck('materia_id');
            return $curriculum->mapWithKeys(fn ($id) => [$id => ['periodos' => []]]);
        }
        return app(GradebookService::class)->report($source)['asignaturas']->keyBy('materia_id');
    }

    /** Prepare before changing the roster. The caller holds the year and enrollment locks. */
    public function prepare(Matricula $enrollment, Grupo $origin, Grupo $destination, string $date): array
    {
        $history = DB::table('matricula_movimientos')->where('matricula_id', $enrollment->id)->orderBy('version')->get();
        $previous = self::active([$enrollment->id])->get()->keyBy(fn ($r) => $r->materia_id.':'.$r->periodo_id);
        $year = $enrollment->ano_lectivo_id;
        $periods = Periodo::where('ano_lectivo_id', $year)->where('estado', 'cerrado')->whereDate('fecha_fin', '<', $date)->orderBy('orden')->get();
        $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $year)->where('grado_id', $destination->grado_id)->pluck('materia_id');
        if ($periods->isEmpty() || $curriculum->isEmpty()) {
            return [];
        }
        $reports = [];
        $rows = [];
        foreach ($periods as $period) {
            $next = $history->first(fn ($m) => $m->fecha_efectiva > $period->fecha_fin->toDateString());
            $sourceGroup = $next->grupo_origen_id ?? $origin->id;
            if ($sourceGroup === $destination->id) {
                continue; // Returning to the group which owns the original period grades.
            }
            foreach ($curriculum as $subject) {
                $target = ['token' => Str::random(24), 'grupo_origen_id' => $sourceGroup, 'materia_id' => $subject, 'periodo_id' => $period->id];
                $carried = $previous->get($subject.':'.$period->id);
                if ($carried && $carried->grupo_origen_id === $sourceGroup) {
                    $rows[] = [...$target, 'resultado' => $carried->resultado, 'motivo' => $carried->motivo];
                    continue;
                }
                $reports[$sourceGroup] ??= $this->sourceSubjects($enrollment, $sourceGroup);
                $sourceSubject = $reports[$sourceGroup]->get($subject);
                $result = collect($sourceSubject['periodos'] ?? [])->firstWhere('periodo_id', $period->id);
                // Approved certified grades and individual assessments already follow the enrollment.
                if (in_array($result['origen'] ?? null, ['externa', 'valoracion', 'colegio', 'pendiente_eleccion', 'pendiente_certificado', 'sin_equivalencia', 'consolidacion', 'pendiente_consolidacion'], true)) {
                    continue;
                }
                $grade = null;
                if (($result['estado'] ?? null) === 'calculado' && isset($result['exact_value'])) {
                    $grade = Arr::only($result, ['estado', 'exact_value', 'raw_value', 'display_value', 'aprobado', 'calculation_version']);
                    $grade['categoria_reconocida'] = $result['valoracion']['nombre'] ?? null;
                }
                $rows[] = [...$target,
                    'resultado' => $grade === null ? null : json_encode($grade, JSON_THROW_ON_ERROR),
                    'motivo' => $grade !== null ? null : ($sourceSubject ? 'La materia de origen no tiene definitiva.' : 'Materia nueva en el grado de destino.')];
            }
        }
        return $rows;
    }
}
