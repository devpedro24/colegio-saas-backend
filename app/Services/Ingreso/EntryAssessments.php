<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EntryAssessments
{
    use RectifiesApprovedGrades;

    public const RECTIFY = 'academico.matriculas.rectificar_valoraciones';
    public const REVISIONS = 'matricula_rectificaciones_valoraciones';
    public const RECTIFICATION_RESOURCE = 'matricula_rectificacion_valoracion';
    public const RESULTS = self::TABLE;
    public const TABLE = 'matricula_valoraciones_ingreso';
    public const REGISTER = 'academico.matriculas.registrar_valoraciones';
    public const APPROVE = 'academico.matriculas.aprobar_valoraciones';

    public function __construct(private EnrollmentGradeContext $context, private EnrollmentIntake $intake) {}

    /** Called after the grant, its enrollment and its closed period have been locked and authorized. */
    public function recordAuthorizedRevision(object $grant, User $actor, array $data): void
    {
        $target = DB::table(self::TABLE)->where('matricula_id', $grant->matricula_id)->where('periodo_id', $grant->periodo_id)
            ->where('materia_id', $grant->materia_id)->whereNull('fecha_ingreso')->where('id', $grant->rectifica_valoracion_id)->lockForUpdate()->firstOrFail();
        $previous = json_decode($target->decision, true, flags: JSON_THROW_ON_ERROR);
        $grade = [...$this->context->grade($data, $previous['siee']), 'opcion_token' => $data['opcion_token'] ?? null];
        $hash = hash('sha256', json_encode([$grant->token, $grade], JSON_THROW_ON_ERROR));
        if ($grant->rectificacion_id !== null) {
            abort_unless(DB::table(self::REVISIONS)->where('id', $grant->rectificacion_id)->where('huella', $hash)->exists(),
                409, 'Esta autorización de rectificación ya fue utilizada.');
            return;
        }
        $before = json_decode($grant->anterior, true, flags: JSON_THROW_ON_ERROR);
        abort_unless($target->version === $before['version'], 409, 'Cambió la definitiva autorizada. Revisa la versión vigente.');
        abort_if($previous['valor'] === $grade['valor'] && $previous['categoria'] === $grade['categoria'], 422, 'La nueva definitiva debe ser distinta de la vigente.');
        $revision = $this->saveRevision($target, $grade, $actor, $grant->motivo, $grant->archivo_token, $grant->archivo_nombre, $hash);
        DB::table(ClosedGradeAuthorizations::TABLE)->where('id', $grant->id)->update(['rectificacion_id' => $revision]);
        AuditLogger::tenant($actor, 'APPROVE', 'autorizacion_definitiva_cerrada', $grant->token, $before,
            [...$grade, 'resultado' => $target->token, 'version' => $target->version + 1, 'soporte' => $grant->archivo_token], $grant->motivo);
    }

    public static function visible(User $actor): bool
    {
        return $actor->canAny([self::REGISTER, self::APPROVE, self::RECTIFY]);
    }

    /** A partial local record also belongs to the student's history. */
    public function priorGrades(Matricula $enrollment): Builder
    {
        $activities = DB::table('calificaciones as g')->join('actividades_evaluacion as a', 'a.id', '=', 'g.actividad_id')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
            ->join('asignaciones_docentes as s', 's.id', '=', 'c.asignacion_id')
            ->where('g.matricula_id', $enrollment->id)->whereNotNull('g.valor')->select('s.materia_id', 'c.periodo_id');
        $manualComponents = DB::table('resultados_manuales_componente as g')->join('componentes_evaluacion as c', 'c.id', '=', 'g.componente_id')
            ->join('asignaciones_docentes as s', 's.id', '=', 'c.asignacion_id')
            ->where('g.matricula_id', $enrollment->id)->where('g.estado', 'vigente')->select('s.materia_id', 'c.periodo_id');
        $manualSubjects = DB::table('resultados_manuales_materia as g')->join('asignaciones_docentes as s', 's.id', '=', 'g.asignacion_id')
            ->where('g.matricula_id', $enrollment->id)->where('g.estado', 'vigente')->select('s.materia_id', 'g.periodo_id');
        $certified = DB::table('matricula_definitivas_externas')->where('matricula_id', $enrollment->id)
            ->whereNotNull('aprobado_en')->select('materia_id', 'periodo_id');

        return DB::query()->fromSub($activities->union($manualComponents)->union($manualSubjects)->union($certified), 'antecedentes');
    }

    public function details(Matricula $enrollment, User $actor): array
    {
        $form = $this->context->form($enrollment);
        $rows = self::readable($enrollment, $actor)->orderBy('id')->get();
        $history = $this->rectificationHistory($enrollment);
        $certified = DB::table(CertifiedExternalGrades::RESULTS)->where('matricula_id', $enrollment->id)
            ->whereNotNull('aprobado_en')->get(['materia_id', 'periodo_id'])->keyBy(fn ($r) => $r->materia_id.':'.$r->periodo_id);
        return [...$form, 'fecha_ingreso' => $this->context->entryDate($enrollment),
            'puede_registrar' => $form['editable'] && $actor->can(self::REGISTER),
            'puede_rectificar' => $form['editable'] && $actor->can(self::RECTIFY),
            'puede_autorizar_cierre' => $actor->can(ClosedGradeAuthorizations::AUTHORIZE),
            'puede_aprobar' => $form['editable'] && $actor->can(self::APPROVE),
            'traslados' => GradeTransferResults::active([$enrollment->id])->orderBy('t.periodo_id')->get()->map(fn ($r) => [
                'token' => $r->token, 'materia_token' => OpaqueUrlToken::for('materia', $r->materia_id),
                'periodo_token' => OpaqueUrlToken::for('periodo', $r->periodo_id), 'fecha_efectiva' => $r->fecha_efectiva,
                'definitiva' => $r->resultado ? self::displayTransferredGrade($r->resultado) : null,
                'tiene_equivalencia' => $certified->has($r->materia_id.':'.$r->periodo_id),
                'motivo' => $r->motivo]),
            'sin_equivalencia' => PendingCertificates::details($enrollment)->whereNotNull('certificado_revisado')->values(),
            'certificados_pendientes' => PendingCertificates::open([$enrollment->id])->whereNull('p.certificado_revisado_id')->get()->map(fn ($r) => [
                'materia_token' => OpaqueUrlToken::for('materia', $r->materia_id), 'periodo_token' => OpaqueUrlToken::for('periodo', $r->periodo_id)]),
            'antecedentes' => $this->priorGrades($enrollment)->get()->map(fn ($r) => [
                'materia_token' => OpaqueUrlToken::for('materia', $r->materia_id), 'periodo_token' => OpaqueUrlToken::for('periodo', $r->periodo_id)]),
            'valoraciones' => $rows->map(fn ($r) => [
                ...Arr::only((array) $r, ['token','motivo','archivo_nombre','archivo_token','registrado_por','registrado_en','aprobado_en']),
                'materia_token' => OpaqueUrlToken::for('materia', $r->materia_id), 'periodo_token' => OpaqueUrlToken::for('periodo', $r->periodo_id),
                'version' => $r->version, 'rectificaciones' => $this->presentRectifications($history, $r),
                'requiere_autorizacion_cierre' => $r->fecha_ingreso === null,
                'propuesta' => json_decode($r->propuesta, true, flags: JSON_THROW_ON_ERROR),
                'decision' => $r->decision ? Arr::except(json_decode($r->decision, true, flags: JSON_THROW_ON_ERROR), ['huella']) : null])];
    }

    public static function readable(Matricula $student, User $actor): Builder
    {
        return DB::table(self::TABLE)->where('matricula_id', $student->id)->where(function ($q) use ($student, $actor) {
            $q->whereNotNull('fecha_ingreso')->orWhereIn('id', ClosedGradeAuthorizations::records($student, $actor)
                ->whereNotNull('a.valoracion_id')->select('a.valoracion_id'));
        });
    }

    private static function displayTransferredGrade(string $snapshot): string
    {
        $result = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        return $result['categoria_reconocida'] ?? $result['display_value'];
    }

    private function ensureNoGrades(Matricula $enrollment, int $subject, int $period, bool $pendingTransfer): void
    {
        abort_if(SplitPeriodGrades::active([$enrollment->id], $subject, $period)->exists(), 409, 'Este periodo se resuelve mediante la consolidación por cambio de grupo.');
        abort_if(PendingCertificates::open([$enrollment->id], $subject, $period)->whereNull('p.certificado_revisado_id')->exists(), 409, 'Esta materia y periodo tienen un certificado en trámite.');
        // A transfer may have partial activities in the previous group. They remain in its record.
        $existing = $pendingTransfer
            ? DB::table(CertifiedExternalGrades::RESULTS)->where('matricula_id', $enrollment->id)->whereNotNull('aprobado_en')
            : $this->priorGrades($enrollment);
        abort_if($existing->where('materia_id', $subject)->where('periodo_id', $period)->exists(),
            409, 'Ya hay notas o una equivalencia en esta materia y periodo. Revisa el historial del estudiante.');
    }

    private function resolveGrade(Matricula $enrollment, array $data): array
    {
        $scale = $this->context->scale($enrollment);
        abort_unless(hash_equals($scale['version'], $data['version_escala']), 409, 'La escala o el grupo cambió. Actualiza la ficha.');
        return [...$this->context->grade($data, $scale), 'siee' => $scale];
    }

    public function register(Matricula $enrollment, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $data, $actor) {
            $locked = $this->context->lockEnrollment($enrollment);
            $periods = Periodo::where('ano_lectivo_id', $locked->ano_lectivo_id);
            $period = $this->intake->resolve('periodo', $data['periodo_token'], clone $periods);
            $subject = $this->intake->resolve('materia', $data['materia_token'], $this->context->subjects($locked));
            $slot = GradeTransferResults::active([$locked->id], $subject->id, $period->id)->first();
            if (isset($data['traslado_token'])) {
                abort_unless($slot && hash_equals($slot->token, $data['traslado_token']), 409, 'El movimiento ya no está vigente. Actualiza la ficha.');
                abort_if($slot->resultado !== null, 409, 'La materia ya tiene una definitiva del grado anterior.');
                $date = $slot->fecha_efectiva;
            } else {
                abort_if($slot, 409, 'Registra esta valoración desde el cambio de grado.');
                $date = $data['fecha_ingreso'];
                $knownDate = $this->context->entryDate($locked);
                abort_if($knownDate && $knownDate !== $date, 422, 'La fecha de ingreso debe coincidir con el expediente.');
                abort_if($date > now(tenant()->zonaHorariaInstitucional())->toDateString()
                    || !$periods->whereDate('fecha_inicio', '<=', $date)->whereDate('fecha_fin', '>=', $date)->exists(),
                    422, 'Selecciona una fecha de ingreso dentro de un periodo del año y hasta hoy.');
            }
            abort_unless($period->fecha_fin->toDateString() < $date, 422, 'Solo puedes valorar un periodo anterior a la fecha registrada.');
            $target = ['matricula_id' => $locked->id, 'materia_id' => $subject->id, 'periodo_id' => $period->id];
            $upload = $data['soporte'];
            $fingerprint = hash('sha256', json_encode([$date, $data['motivo'], $data['valor'] ?? null, $data['opcion_token'] ?? null,
                $data['version_escala'], hash_file('sha256', $upload->getRealPath())], JSON_THROW_ON_ERROR));
            if ($existing = DB::table(self::TABLE)->where($target)->first()) {
                abort_unless(hash_equals($existing->huella, $fingerprint), 409, 'Ya hay una valoración registrada para esta materia y periodo.');
                return;
            }
            $this->ensureNoGrades($locked, $subject->id, $period->id, $slot !== null);
            $grade = $this->resolveGrade($locked, $data);
            $proposal = ['materia' => $subject->nombre, 'periodo' => $period->nombre, 'valor' => $grade['valor'], 'categoria' => $grade['categoria'],
                'version_escala' => $grade['siee']['version'], 'escala' => $grade['siee']['nombre'], 'opcion_token' => $data['opcion_token'] ?? null];
            $token = Str::random(24);
            $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $locked->id).'/valoraciones/'.$token;
            $file = EnrollmentEvidenceFile::store($upload, $folder, $actor, 'soporte');
            DB::table(self::TABLE)->insert([...$target, 'resultado_traslado_id' => $slot?->id, 'token' => $token, 'fecha_ingreso' => $date, 'registrador_id' => $actor->id,
                'registrado_por' => $actor->name, 'registrado_en' => now(), 'motivo' => $data['motivo'], 'huella' => $fingerprint,
                'propuesta' => json_encode($proposal, JSON_THROW_ON_ERROR), 'archivo_token' => $file->public_token, 'archivo_nombre' => $file->original_name]);
            AuditLogger::tenant($actor, 'CREATE', 'matricula_valoracion_ingreso', (string) $locked->id, null,
                ['valoracion' => $token, 'soporte' => $file->public_token, 'propuesta' => $proposal], $data['motivo']);
        });
    }

    public function approve(Matricula $enrollment, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $token, $data, $actor) {
            $locked = $this->context->lockEnrollment($enrollment);
            $row = DB::table(self::TABLE)->where('matricula_id', $locked->id)->where('token', $token)->first() ?? abort(404);
            $fingerprint = hash('sha256', json_encode([$data['criterio'], $data['valor'] ?? null, $data['opcion_token'] ?? null, $data['version_escala']], JSON_THROW_ON_ERROR));
            if ($row->decision) {
                abort_unless(json_decode($row->decision, true)['huella'] === $fingerprint, 409, 'Esta valoración ya fue aprobada.');
                return;
            }
            abort_unless($this->context->subjects($locked)->whereKey($row->materia_id)->exists(), 422, 'La materia ya no pertenece al currículo del estudiante.');
            $period = Periodo::where('ano_lectivo_id', $locked->ano_lectivo_id)->findOrFail($row->periodo_id);
            abort_unless($period->fecha_fin->toDateString() < $row->fecha_ingreso, 422, 'El periodo ya no es anterior al ingreso registrado.');
            if ($row->resultado_traslado_id !== null) {
                $initial = DB::table(GradeTransferResults::TABLE)->find($row->resultado_traslado_id);
                $slot = GradeTransferResults::active([$locked->id], $row->materia_id, $row->periodo_id)->first();
                abort_unless($slot && ! $slot->resultado && $slot->grupo_origen_id === $initial->grupo_origen_id,
                    409, 'La valoración ya no corresponde al cambio de grado vigente.');
            }
            $this->ensureNoGrades($locked, $row->materia_id, $row->periodo_id, $row->resultado_traslado_id !== null);
            $decision = [...$this->resolveGrade($locked, $data), 'responsable' => $actor->name, 'criterio' => $data['criterio'], 'huella' => $fingerprint];
            DB::table(self::TABLE)->where('id', $row->id)->update(['decision' => json_encode($decision, JSON_THROW_ON_ERROR),
                'aprobador_id' => $actor->id, 'aprobado_en' => now()]);
            AuditLogger::tenant($actor, 'APPROVE', 'matricula_valoracion_ingreso', (string) $locked->id, null,
                ['valoracion' => $token, 'soporte' => $row->archivo_token, 'valor' => $decision['valor'], 'version_escala' => $data['version_escala']], $data['criterio']);
        });
    }
}
