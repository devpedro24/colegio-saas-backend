<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RectifiesApprovedGrades
{
    private function rectificationHistory(Matricula $enrollment): Collection
    {
        return DB::table(self::REVISIONS.' as h')->join(self::RESULTS.' as r', 'r.id', '=', 'h.resultado_id')
            ->where('r.matricula_id', $enrollment->id)->orderBy('h.version')->get(['h.*'])->groupBy('resultado_id');
    }

    private function presentRectifications(Collection $history, object $r): Collection
    {
        return $history->get($r->id, collect())->where('version', '<=', $r->version)->map(function ($change) {
            $prior = json_decode($change->decision_anterior, true, flags: JSON_THROW_ON_ERROR);
            $next = json_decode($change->decision_nueva, true, flags: JSON_THROW_ON_ERROR);
            return [...Arr::only((array) $change, ['version','aprobacion_anterior','registrado_en','motivo','archivo_token','archivo_nombre']),
                'anterior' => Arr::only($prior, ['valor','categoria','responsable','criterio']),
                'nueva' => Arr::only($next, ['valor','categoria','responsable'])];
        })->values();
    }

    public function rectify(Matricula $enrollment, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $token, $data, $actor) {
            $locked = $this->context->lockEnrollment($enrollment);
            $result = DB::table(self::RESULTS)->where('matricula_id', $locked->id)->where('token', $token)->firstOrFail();
            abort_if($this instanceof EntryAssessments && $result->fecha_ingreso === null, 409,
                'Solicita una nueva autorización individual para rectificar esta definitiva.');
            abort_unless($result->decision !== null, 422, 'Primero se debe aprobar la definitiva.');
            $previous = json_decode($result->decision, true, flags: JSON_THROW_ON_ERROR);
            // A correction uses the grading scale attached to the approved decision.
            $grade = $this->context->grade($data, $previous['siee']);
            $version = (int) $data['version'] + 1;
            $upload = $data['soporte'];
            $fingerprint = hash('sha256', json_encode([$version, $actor->id, $grade, $data['motivo'], hash_file('sha256', $upload->getRealPath())], JSON_THROW_ON_ERROR));
            if ($result->version === $version && DB::table(self::REVISIONS)->where('resultado_id', $result->id)
                ->where('version', $version)->where('huella', $fingerprint)->exists()) {
                return;
            }
            abort_unless($result->version === $version - 1, 409, 'La definitiva cambió. Actualiza la ficha y revisa la definitiva vigente.');
            abort_if($previous['valor'] === $grade['valor'] && $previous['categoria'] === $grade['categoria'],
                422, 'La nueva definitiva debe ser distinta de la vigente.');
            $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $locked->id).'/rectificaciones/'.Str::uuid();
            $file = EnrollmentEvidenceFile::store($upload, $folder, $actor, 'soporte');
            $this->saveRevision($result, $grade, $actor, $data['motivo'], $file->public_token, $file->original_name, $fingerprint);
        });
    }

    /** Called inside the transaction holding the enrollment lock. */
    private function saveRevision(object $result, array $grade, User $actor, string $reason, string $fileToken, string $fileName, string $fingerprint): int
    {
        $previous = json_decode($result->decision, true, flags: JSON_THROW_ON_ERROR);
        $version = $result->version + 1;
        $next = [...$previous, ...$grade, 'criterio' => $reason, 'responsable' => $actor->name];
        $encoded = json_encode($next, JSON_THROW_ON_ERROR);
        $date = now();
        $revision = DB::table(self::REVISIONS)->insertGetId(['resultado_id' => $result->id, 'version' => $version,
            'decision_anterior' => $result->decision, 'aprobador_anterior_id' => $result->aprobador_id,
            'aprobacion_anterior' => $result->aprobado_en, 'decision_nueva' => $encoded, 'motivo' => $reason,
            'actor_id' => $actor->id, 'registrado_en' => $date, 'archivo_token' => $fileToken,
            'archivo_nombre' => $fileName, 'huella' => $fingerprint]);
        DB::table(self::RESULTS)->where('id', $result->id)->update(['version' => $version, 'decision' => $encoded,
            'aprobador_id' => $actor->id, 'aprobado_en' => $date]);
        AuditLogger::tenant($actor, 'UPDATE', self::RECTIFICATION_RESOURCE, (string) $result->matricula_id,
            ['version' => $result->version, 'valor' => $previous['valor'], 'categoria' => $previous['categoria']],
            ['version' => $version, 'resultado' => $result->token, 'materia_id' => $result->materia_id, 'periodo_id' => $result->periodo_id,
                ...Arr::only($next, ['materia', 'periodo']), ...$grade, 'soporte' => $fileToken], $reason);
        return $revision;
    }
}
