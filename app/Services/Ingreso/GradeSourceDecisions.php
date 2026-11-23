<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\RecognizedPeriodGrades;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GradeSourceDecisions
{
    public const CHOOSE = 'academico.matriculas.elegir_definitiva';
    public const TABLE = 'matricula_elecciones_definitiva';

    /** Freeze the ordinary final before the approved certificate protects this period. */
    public static function capture(object $row, bool $historical = false): void
    {
        $scale = json_decode($row->decision, true, flags: JSON_THROW_ON_ERROR)['siee'];
        $student = Matricula::findOrFail($row->matricula_id);
        $group = OpaqueUrlToken::find('grupo', $scale['grupo_token'], Grupo::withTrashed());
        $assignment = AsignacionDocente::withTrashed()->where('grupo_id', $group?->id)
            ->where('ano_lectivo_id', $student->ano_lectivo_id)->where('materia_id', $row->materia_id)
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('id')->first();
        if (!$assignment) {
            return;
        }
        $grade = app(GradebookService::class)->localSubjectResult($assignment, $student, Periodo::withTrashed()->findOrFail($row->periodo_id),
            [...$scale['reglas'], 'decimales' => $scale['decimales'], 'nota_minima' => $scale['metodo']['nota_minima']]);
        if ($grade['estado'] === 'calculado') {
            // Preserve the official result of records already closed when this feature was introduced.
            if ($historical && ($student->estado !== 'activa' || $student->anoLectivo->estaCerrado())) {
                $grade['version_certificada_al_cierre'] = $row->version;
            }
            DB::table(CertifiedExternalGrades::RESULTS)->where('id', $row->id)->update([
                'definitiva_planilla' => json_encode($grade, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /** The same comparison drives the register, gradebook, annual calculation and report. */
    public static function comparisons(Collection $rows, bool $includeHistory = false): Collection
    {
        $assessments = $rows->where('origen', 'valoracion')->keyBy(fn ($r) => self::slot($r));
        $certificates = $rows->where('origen', 'externa')->filter(fn ($r) => $r->definitiva_planilla !== null || $assessments->has(self::slot($r)));
        if ($certificates->isEmpty()) {
            return collect();
        }
        $query = DB::table(self::TABLE)->whereIn('resultado_id', $certificates->pluck('id'));
        if (!$includeHistory) {
            $latest = (clone $query)->selectRaw('max(id)')->groupBy('resultado_id');
            $query->whereIn('id', $latest);
        }
        $history = $query->orderByDesc('version')->get()->groupBy('resultado_id');
        return $certificates->mapWithKeys(function ($r) use ($assessments, $history, $includeHistory) {
            $assessment = $assessments->get(self::slot($r));
            $local = $assessment ? RecognizedPeriodGrades::approvedResult($assessment)
                : json_decode($r->definitiva_planilla, true, flags: JSON_THROW_ON_ERROR);
            $certified = RecognizedPeriodGrades::approvedResult($r);
            $notes = ['colegio' => $local['display_value'], 'certificado' => $certified['display_value']];
            $fingerprint = hash('sha256', json_encode([$r->token, $r->version, $local['exact_value'], $assessment?->token, $assessment?->version], JSON_THROW_ON_ERROR));
            $changes = $history->get($r->id, collect());
            $last = $changes->first();
            $historical = !$last && ($local['version_certificada_al_cierre'] ?? null) === $r->version;
            unset($local['version_certificada_al_cierre']);
            $source = $historical ? 'certificado' : null;
            if ($last && $last->huella === $fingerprint) {
                $source = $last->fuente;
            }
            $effective = match ($source) {
                'colegio' => [...$local, 'origen' => $assessment ? 'valoracion' : 'colegio'],
                'certificado' => $certified,
                default => ['estado' => 'pendiente', 'origen' => 'pendiente_eleccion', 'motivo' => 'Elige la definitiva del colegio o la del certificado.'],
            };
            $public = [...$notes, 'fuente' => $source, 'historica' => $historical, 'huella' => $fingerprint, 'version' => $last?->version ?? 0];
            if ($includeHistory) {
                $public['historial'] = $changes->map(fn ($c) => [
                    ...Arr::only((array) $c, ['version', 'fuente', 'responsable', 'motivo', 'registrado_en']),
                    ...json_decode($c->notas, true, flags: JSON_THROW_ON_ERROR),
                ])->values();
            }
            return [$r->token => ['resultado' => $effective, 'comparacion' => $public]];
        });
    }

    private static function slot(object $r): string
    {
        return $r->matricula_id.':'.$r->materia_id.':'.$r->periodo_id;
    }

    public function choose(Matricula $enrollment, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $token, $data, $actor) {
            $locked = app(EnrollmentGradeContext::class)->lockEnrollment($enrollment);
            $row = DB::table(CertifiedExternalGrades::RESULTS)->where('matricula_id', $locked->id)->where('token', $token)->firstOrFail();
            abort_unless($row->aprobado_en, 422, 'Primero aprueba la equivalencia.');
            $rows = RecognizedPeriodGrades::approved([$locked->id], $row->materia_id, $row->periodo_id)->get();
            $case = self::comparisons($rows)->get($token);
            abort_unless($case, 422, 'Se necesitan dos definitivas para elegir la fuente.');
            $comparison = $case['comparacion'];
            abort_unless(hash_equals($comparison['huella'], $data['huella']), 409, 'Las notas cambiaron. Revisa ambas antes de elegir.');
            $version = (int) $data['version'] + 1;
            $reason = trim($data['motivo']);
            $record = ['resultado_id' => $row->id, 'version' => $version, 'motivo' => $reason,
                'actor_id' => $actor->id, 'fuente' => $data['fuente'], 'huella' => $data['huella']];
            if ($comparison['version'] === $version && DB::table(self::TABLE)->where($record)->exists()) {
                return;
            }
            abort_unless($comparison['version'] === $version - 1, 409, 'Otra persona actualizó la elección. Revisa el historial.');
            $notes = Arr::only($comparison, ['colegio', 'certificado']);
            DB::table(self::TABLE)->insert([...$record, 'notas' => json_encode($notes, JSON_THROW_ON_ERROR),
                'responsable' => $actor->name, 'registrado_en' => now()]);
            AuditLogger::tenant($actor, 'UPDATE', 'matricula_eleccion_definitiva', (string) $locked->id,
                ['fuente' => $comparison['fuente']], [...$notes, 'fuente' => $data['fuente'], 'resultado' => $token, 'version' => $version], $reason);
        });
    }
}
