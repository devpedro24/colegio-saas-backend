<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\RecognizedPeriodGrades;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClosedGradeAuthorizations
{
    public const TABLE = 'matricula_autorizaciones_cierre';
    public const AUTHORIZE = 'academico.matriculas.autorizar_definitiva_cerrada';

    public function __construct(private EnrollmentGradeContext $context, private EnrollmentIntake $intake) {}

    public static function visible(User $actor): bool
    {
        return $actor->canAny([self::AUTHORIZE, EntryAssessments::REGISTER, EntryAssessments::RECTIFY]);
    }

    public static function records(Matricula $student, User $actor)
    {
        return DB::table(self::TABLE.' as a')->where('a.matricula_id', $student->id)
            ->when(!$actor->can(self::AUTHORIZE), fn ($q) => $q->where('a.responsable_id', $actor->id));
    }

    public function details(Matricula $student, User $actor): array
    {
        $form = $this->context->form($student);
        $active = $form['editable'] && $student->anoLectivo->estado === AnoLectivo::ESTADO_EN_CURSO;
        $canAuthorize = $actor->can(self::AUTHORIZE);
        $closed = Periodo::where('ano_lectivo_id', $student->ano_lectivo_id)->where('estado', Periodo::ESTADO_CERRADO)->orderBy('orden')->get();
        $rows = self::records($student, $actor)->join('materias as m', 'm.id', '=', 'a.materia_id')
            ->join('periodos as p', 'p.id', '=', 'a.periodo_id')->leftJoin(EntryAssessments::TABLE.' as v', 'v.id', '=', 'a.valoracion_id')
            ->leftJoin(EntryAssessments::TABLE.' as target', 'target.id', '=', 'a.rectifica_valoracion_id')
            ->leftJoin(EntryAssessments::REVISIONS.' as h', 'h.id', '=', 'a.rectificacion_id')
            ->orderByDesc('a.id')->get(['a.*','m.nombre as materia','p.nombre as periodo','p.estado as estado_periodo',
                'v.propuesta','v.decision','v.version','v.registrado_en','target.decision as objetivo','h.decision_nueva','h.registrado_en as rectificado_en']);
        $owners = fn (string $permission) => $active && $canAuthorize ? PendingCertificates::owners($actor, $permission)->orderBy('name')->get()
            ->map(fn ($u) => ['token' => OpaqueUrlToken::for('usuario', $u->id), 'nombre' => $u->name]) : [];
        $pendingTargets = $rows->whereNull('revocado_en')->whereNull('rectificacion_id')->pluck('rectifica_valoracion_id')->filter();
        return [...Arr::only($form, ['escala','error_escala','max_bytes','materias']),
            'puede_autorizar' => $active && $canAuthorize,
            'periodos' => $closed->map(fn ($p) => ['token' => OpaqueUrlToken::for('periodo', $p->id), 'nombre' => $p->nombre]),
            'responsables' => $owners(EntryAssessments::REGISTER), 'responsables_rectificacion' => $owners(EntryAssessments::RECTIFY),
            'autorizaciones' => $rows->map(function ($r) use ($actor, $active, $form, $canAuthorize, $pendingTargets) {
                $correction = $r->rectifica_valoracion_id !== null;
                $pending = !$r->revocado_en && !$r->valoracion_id && !$r->rectificacion_id;
                $original = json_decode($r->anterior, true, flags: JSON_THROW_ON_ERROR);
                $recorded = json_decode($r->decision_nueva ?? $r->propuesta ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                $current = json_decode($r->decision ?? $r->objetivo ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                return [...Arr::only((array) $r, ['token','materia','periodo','autorizado_por','autorizado_en','responsable','motivo',
                    'archivo_token','archivo_nombre','revocado_en']),
                    'aprobado_en' => $r->rectificado_en ?? $r->registrado_en, 'es_rectificacion' => $correction,
                    'materia_token' => OpaqueUrlToken::for('materia', $r->materia_id), 'periodo_token' => OpaqueUrlToken::for('periodo', $r->periodo_id),
                    'vigente' => $current['categoria'] ?? $current['valor'] ?? null, 'version' => $r->version,
                    'escala_rectificacion' => $correction && $pending ? Arr::only($current['siee'], ['nombre','minimo','maximo','decimales','opciones']) : null,
                    'puede_autorizar_rectificacion' => $canAuthorize && $active && $form['escala'] !== null && $r->estado_periodo === Periodo::ESTADO_CERRADO
                        && $r->valoracion_id !== null && !$pendingTargets->contains($r->valoracion_id),
                    'anterior' => $original['display_value'] ?? null, 'registrada' => $recorded['categoria'] ?? $recorded['valor'] ?? null,
                    'puede_revocar' => $pending && $active && $canAuthorize,
                    'puede_registrar' => $pending && $active && $r->responsable_id === $actor->id && $actor->can($correction ? EntryAssessments::RECTIFY : EntryAssessments::REGISTER)
                        && $r->estado_periodo === Periodo::ESTADO_CERRADO && $r->version_escala === ($form['escala']['version'] ?? null)];
            })];
    }

    private function lockStudent(Matricula $student): Matricula
    {
        $student = $this->context->lockEnrollment($student);
        abort_unless($student->anoLectivo->estado === AnoLectivo::ESTADO_EN_CURSO, 422, 'El año lectivo debe estar en curso.');
        return $student;
    }

    /** Compare against the result that the grantor saw. Activities remain in their original grade sheet. */
    private function baseline(Matricula $student, int $subject, int $period): array
    {
        $occupied = RecognizedPeriodGrades::forEnrollments([$student->id], $subject, $period)->get($student->id, $subject, $period);
        abort_if($occupied || DB::table(EntryAssessments::TABLE)->where('matricula_id', $student->id)->where('materia_id', $subject)
            ->where('periodo_id', $period)->exists(), 409, 'Esta materia y periodo ya tienen un trámite académico. Revísalo en la ficha.');
        abort_if(RecuperacionAcademica::join('asignaciones_docentes as s', 's.id', '=', 'asignacion_id')
            ->where('matricula_id', $student->id)->where('s.materia_id', $subject)->where('periodo_id', $period)
            ->where('recuperaciones_academicas.estado', '!=', 'anulada')->exists(), 409, 'Revisa primero la recuperación del estudiante.');
        $row = collect(app(GradebookService::class)->report($student)['asignaturas'])->firstWhere('materia_id', $subject);
        $grade = collect($row['periodos'] ?? [])->firstWhere('periodo_id', $period);
        abort_if(!$grade || isset($grade['resultado_original']), 409, 'Revisa el currículo o la recuperación vigente antes de incorporar una definitiva.');
        return Arr::only($grade, ['estado','exact_value','display_value']);
    }

    public function authorize(Matricula $student, User $actor, array $data): void
    {
        DB::transaction(function () use ($student, $actor, $data) {
            $student = $this->lockStudent($student);
            $subject = $this->intake->resolve('materia', $data['materia_token'], $this->context->subjects($student));
            $period = $this->intake->resolve('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $student->ano_lectivo_id)->lockForUpdate());
            abort_unless($period->estado === Periodo::ESTADO_CERRADO, 422, 'Selecciona un periodo cerrado.');
            $correction = isset($data['rectifica_token']);
            $owner = $this->intake->resolve('usuario', $data['responsable_token'], PendingCertificates::owners($actor, $correction ? EntryAssessments::RECTIFY : EntryAssessments::REGISTER));
            $key = ['matricula_id' => $student->id, 'materia_id' => $subject->id, 'periodo_id' => $period->id];
            $scale = $this->context->scale($student);
            $reason = trim($data['motivo']);
            $upload = $data['soporte'];
            $parts = [$actor->id, $owner->id, $reason, $scale['version'], hash_file('sha256', $upload->getRealPath())];
            if ($correction) {
                $parts[] = [$data['rectifica_token'], (int) $data['version']];
            }
            $hash = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
            // A delayed retry belongs to its original grant, even after that grant was revoked.
            $token = substr(hash('sha256', implode('|', [tenant()->getKey(), $student->id, $actor->id, $data['solicitud_token']])), 0, 24);
            $previous = DB::table(self::TABLE)->where('token', $token)->first();
            if ($previous) {
                abort_unless($previous->materia_id === $subject->id && $previous->periodo_id === $period->id
                    && hash_equals($previous->huella, $hash), 409, 'Esta solicitud ya se procesó con otros datos.');
                abort_if($previous->revocado_en, 409, 'Esta autorización fue revocada.');
                return;
            }
            $grants = DB::table(self::TABLE)->where($key);
            $target = null;
            if ($correction) {
                $root = (clone $grants)->where('token', $data['rectifica_token'])->whereNotNull('valoracion_id')->firstOrFail();
                $target = DB::table(EntryAssessments::TABLE)->where($key)->whereNull('fecha_ingreso')->where('id', $root->valoracion_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->version === (int) $data['version'], 409, 'La definitiva cambió. Revisa la versión vigente.');
                $previousGrade = json_decode($target->decision, true, flags: JSON_THROW_ON_ERROR);
                $before = ['display_value' => $previousGrade['categoria'] ?? $previousGrade['valor'], 'version' => $target->version];
                $grants->whereNull('valoracion_id')->whereNull('rectificacion_id');
            }
            abort_if($grants->whereNull('revocado_en')->exists(), 409, 'Ya existe una autorización para esta materia y periodo. Consulta su historial.');
            $before ??= $this->baseline($student, $subject->id, $period->id);
            $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $student->id).'/autorizaciones/'.$token;
            $file = EnrollmentEvidenceFile::store($upload, $folder, $actor, 'soporte');
            DB::table(self::TABLE)->insert([...$key, 'token' => $token, 'motivo' => $reason, 'huella' => $hash, 'rectifica_valoracion_id' => $target?->id,
                'responsable_id' => $owner->id, 'responsable' => $owner->name, 'autorizador_id' => $actor->id,
                'autorizado_por' => $actor->name, 'autorizado_en' => now(), 'version_escala' => $scale['version'],
                'anterior' => json_encode($before, JSON_THROW_ON_ERROR), 'archivo_token' => $file->public_token, 'archivo_nombre' => $file->original_name]);
            AuditLogger::tenant($actor, 'CREATE', 'autorizacion_definitiva_cerrada', $token, $before,
                [...$key, 'responsable' => $owner->name, 'soporte' => $file->public_token], $reason);
        });
    }

    public function register(Matricula $student, string $token, User $actor, array $data): void
    {
        DB::transaction(function () use ($student, $token, $actor, $data) {
            $student = $this->lockStudent($student);
            $grant = DB::table(self::TABLE)->where('matricula_id', $student->id)->where('token', $token)->lockForUpdate()->firstOrFail();
            $correction = $grant->rectifica_valoracion_id !== null;
            abort_unless($grant->responsable_id === $actor->id && $actor->can($correction ? EntryAssessments::RECTIFY : EntryAssessments::REGISTER), 403);
            abort_if($grant->revocado_en, 409, 'La autorización fue revocada.');
            $period = Periodo::where('ano_lectivo_id', $student->ano_lectivo_id)->lockForUpdate()->findOrFail($grant->periodo_id);
            abort_unless($period->estado === Periodo::ESTADO_CERRADO, 422, 'El periodo debe estar cerrado.');
            $subject = $this->context->subjects($student)->findOrFail($grant->materia_id);
            $scale = $this->context->scale($student);
            abort_unless($grant->version_escala === $scale['version'] && $scale['version'] === $data['version_escala'],
                409, 'Cambió la escala o el grupo. Revisa la autorización.');
            if ($correction) {
                app(EntryAssessments::class)->recordAuthorizedRevision($grant, $actor, $data);
                return;
            }
            $grade = $this->context->grade($data, $scale);
            $hash = hash('sha256', json_encode([$token, $grade], JSON_THROW_ON_ERROR));
            if ($grant->valoracion_id) {
                $saved = DB::table(EntryAssessments::TABLE)->where('id', $grant->valoracion_id)->value('huella');
                abort_unless($saved === $hash, 409, 'La autorización ya se utilizó. La nota registrada está en el historial.');
                return;
            }
            $before = $this->baseline($student, $subject->id, $period->id);
            abort_unless($before == json_decode($grant->anterior, true, flags: JSON_THROW_ON_ERROR), 409, 'Cambió la definitiva anterior. Revisa la autorización.');
            $proposal = [...$grade, 'materia' => $subject->nombre, 'periodo' => $period->nombre, 'version_escala' => $scale['version'],
                'escala' => $scale['nombre'], 'opcion_token' => $data['opcion_token'] ?? null];
            $decision = [...$proposal, 'siee' => $scale, 'criterio' => $grant->motivo, 'responsable' => $actor->name, 'huella' => $hash];
            $now = now();
            $result = DB::table(EntryAssessments::TABLE)->insertGetId(['matricula_id' => $student->id, 'materia_id' => $subject->id,
                'periodo_id' => $period->id, 'token' => Str::random(24), 'fecha_ingreso' => null, 'huella' => $hash,
                'registrador_id' => $actor->id, 'registrado_por' => $actor->name, 'registrado_en' => $now,
                'motivo' => $grant->motivo, 'archivo_token' => $grant->archivo_token, 'archivo_nombre' => $grant->archivo_nombre,
                'propuesta' => json_encode($proposal, JSON_THROW_ON_ERROR), 'decision' => json_encode($decision, JSON_THROW_ON_ERROR),
                'aprobador_id' => $actor->id, 'aprobado_en' => $now]);
            DB::table(self::TABLE)->where('id', $grant->id)->update(['valoracion_id' => $result]);
            AuditLogger::tenant($actor, 'APPROVE', 'autorizacion_definitiva_cerrada', $token, $before,
                [...$grade, 'soporte' => $grant->archivo_token, 'materia' => $subject->nombre, 'periodo' => $period->nombre], $grant->motivo);
        });
    }

    public function revoke(Matricula $student, string $token, User $actor, string $reason): void
    {
        DB::transaction(function () use ($student, $token, $actor, $reason) {
            $student = $this->lockStudent($student);
            $grant = DB::table(self::TABLE)->where('matricula_id', $student->id)->where('token', $token)->lockForUpdate()->firstOrFail();
            abort_if($grant->valoracion_id || $grant->rectificacion_id, 409, 'La autorización ya fue utilizada.');
            if (!$grant->revocado_en) {
                DB::table(self::TABLE)->where('id', $grant->id)->update(['revocado_en' => now()]);
                AuditLogger::tenant($actor, 'UPDATE', 'autorizacion_definitiva_cerrada', $token, ['estado' => 'autorizada'], ['estado' => 'revocada'], $reason);
            }
        });
    }
}
