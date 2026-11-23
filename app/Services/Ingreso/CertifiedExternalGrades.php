<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\RecognizedPeriodGrades;
use App\Support\AcademicDecimal;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CertifiedExternalGrades
{
    use RectifiesApprovedGrades;

    public const RECTIFICATION_RESOURCE = 'matricula_rectificacion_equivalencia';
    public const REGISTER = 'academico.matriculas.registrar_externas';
    public const APPROVE = 'academico.matriculas.aprobar_equivalencias';
    public const RECTIFY = 'academico.matriculas.rectificar_equivalencias';
    public const REVISIONS = 'matricula_rectificaciones_externas';
    public const CERTIFICATES = 'matricula_certificados_externos';
    public const RESULTS = 'matricula_definitivas_externas';

    public static function visible(User $actor): bool
    {
        return $actor->canAny([self::REGISTER, self::APPROVE, self::RECTIFY, GradeSourceDecisions::CHOOSE]);
    }

    public function __construct(private EnrollmentGradeContext $context) {}

    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function details(Matricula $enrollment, User $actor): array
    {
        $certificates = DB::table(self::CERTIFICATES)->where('matricula_id', $enrollment->id)->orderByDesc('id')->get();
        $results = DB::table(self::RESULTS)->where('matricula_id', $enrollment->id)->orderBy('id')->get()->groupBy('certificado_id');
        $choices = GradeSourceDecisions::comparisons(RecognizedPeriodGrades::approved([$enrollment->id])->get(), true);
        $history = $this->rectificationHistory($enrollment);
        $form = $this->context->form($enrollment);
        return [...$form, 'fecha_ingreso' => $this->context->entryDate($enrollment),
            'puede_registrar' => $form['editable'] && $actor->can(self::REGISTER),
            'puede_aprobar' => $form['editable'] && $actor->can(self::APPROVE),
            'puede_elegir' => $form['editable'] && $actor->can(GradeSourceDecisions::CHOOSE),
            'puede_rectificar' => $form['editable'] && $actor->can(self::RECTIFY),
            'pendientes' => PendingCertificates::details($enrollment),
            'responsables' => $form['editable'] && $actor->can(self::REGISTER) ? PendingCertificates::owners($actor)->orderBy('name')->get()
                ->map(fn ($u) => ['token' => OpaqueUrlToken::for('usuario', $u->id), 'nombre' => $u->name]) : [],
            'responsables_valoracion' => $form['editable'] && $actor->can(self::APPROVE) ? PendingCertificates::owners($actor, EntryAssessments::REGISTER)
                ->orderBy('name')->get()->map(fn ($u) => ['token' => OpaqueUrlToken::for('usuario', $u->id), 'nombre' => $u->name]) : [],
            'certificados' => $certificates->map(fn ($c) => [
                ...Arr::only((array) $c, ['colegio_origen','grado_origen','escala_origen','fecha_ingreso','periodo_ingreso',
                    'archivo_token','archivo_nombre','registrado_por','registrado_en']),
                'resultados' => $results->get($c->id, collect())->map(fn ($r) => [
                    ...Arr::only((array) $r, ['token','materia_origen','periodo_origen','desde','hasta','valor_original','aprobado_en']),
                    'version' => $r->version,
                    'eleccion' => $choices->get($r->token)['comparacion'] ?? null,
                    'rectificaciones' => $this->presentRectifications($history, $r),
                    'materia_token' => $r->materia_id ? OpaqueUrlToken::for('materia', $r->materia_id) : null,
                    'periodo_token' => $r->periodo_id ? OpaqueUrlToken::for('periodo', $r->periodo_id) : null,
                    'decision' => $r->decision ? Arr::except(json_decode($r->decision, true, flags: JSON_THROW_ON_ERROR), ['huella']) : null])->values()]),
        ];
    }

    public function register(Matricula $enrollment, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $data, $actor) {
            $locked = $this->context->lockEnrollment($enrollment);
            $header = Arr::only($data, ['colegio_origen','grado_origen','escala_origen','fecha_ingreso']);
            $upload = $data['certificado'];
            $digest = hash_file('sha256', $upload->getRealPath());
            // A retry may serialize the multipart fields in a different order.
            $certifiedRows = array_map(function (array $row) {
                if (empty($row['pendiente_token'])) {
                    unset($row['pendiente_token']);
                }
                ksort($row);
                return $row;
            }, $data['resultados']);
            ksort($header);
            $fingerprint = $this->hash([$header, $certifiedRows]);
            $certificates = DB::table(self::CERTIFICATES)->where('matricula_id', $locked->id);
            $existing = (clone $certificates)->where('sha256', $digest)->first();
            if ($existing) {
                abort_unless($existing->huella === $fingerprint, 409, 'Este certificado ya se registró con otros datos.');
                return;
            }
            $date = $header['fecha_ingreso'];
            abort_if($date > now(tenant()->zonaHorariaInstitucional())->toDateString(), 422, 'La fecha de ingreso no puede ser futura.');
            $entryDate = $this->context->entryDate($locked);
            abort_if($entryDate && $entryDate !== $date, 422, 'La fecha de ingreso debe coincidir con el expediente.');
            $period = Periodo::where('ano_lectivo_id', $locked->ano_lectivo_id)
                ->where('fecha_inicio', '<=', $date)->where('fecha_fin', '>=', $date)->first();
            abort_unless($period, 422, 'La fecha de ingreso debe pertenecer a un periodo del año de la matrícula.');
            foreach ($data['resultados'] as $result) {
                abort_if($result['desde'] > $result['hasta'] || $result['hasta'] >= $date, 422,
                    'Cada periodo certificado debe terminar antes del ingreso y tener fechas en orden.');
            }
            $links = [];
            foreach ($data['resultados'] as $index => $result) {
                if (empty($result['pendiente_token'])) {
                    continue;
                }
                $pending = DB::table(PendingCertificates::TABLE)->where('matricula_id', $locked->id)
                    ->where('token', $result['pendiente_token'])->firstOrFail();
                abort_if($pending->certificado_revisado_id !== null, 409, 'El periodo está en valoración por falta de equivalencia.');
                abort_if($pending->resultado_id !== null || in_array($pending->id, $links, true), 409,
                    'Este pendiente ya está vinculado a un resultado certificado.');
                $links[$index] = $pending->id;
            }
            $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $locked->id).'/certificados/'.Str::uuid();
            $file = EnrollmentEvidenceFile::store($upload, $folder, $actor, 'certificado');
            $certificate = DB::table(self::CERTIFICATES)->insertGetId([...$header, 'matricula_id' => $locked->id,
                'periodo_ingreso_id' => $period->id, 'periodo_ingreso' => $period->nombre,
                'archivo_token' => $file->public_token, 'archivo_nombre' => $file->original_name, 'sha256' => $digest,
                'huella' => $fingerprint, 'actor_id' => $actor->id, 'registrado_por' => $actor->name, 'registrado_en' => now()]);
            foreach ($data['resultados'] as $index => $result) {
                $resultId = DB::table(self::RESULTS)->insertGetId([...Arr::except($result, ['pendiente_token']), 'matricula_id' => $locked->id, 'certificado_id' => $certificate,
                    'token' => Str::random(24)]);
                if (isset($links[$index])) {
                    DB::table(PendingCertificates::TABLE)->where('id', $links[$index])->update(['resultado_id' => $resultId]);
                }
            }
            AuditLogger::tenant($actor, 'CREATE', 'matricula_certificado_externo', (string) $locked->id, null,
                ['certificado' => $file->public_token, 'periodo_ingreso' => $period->nombre, 'resultados' => count($data['resultados'])]);
        });
    }

    public function approve(Matricula $enrollment, string $token, array $data, User $actor): void
    {
        DB::transaction(function () use ($enrollment, $token, $data, $actor) {
            $locked = $this->context->lockEnrollment($enrollment);
            $row = DB::table(self::RESULTS)->where('matricula_id', $locked->id)->where('token', $token)->first() ?? abort(404);
            $fingerprint = $this->hash([$data['materia_token'], $data['periodo_token'], $data['version_escala'],
                AcademicDecimal::normalize($data['valor'] ?? null), $data['opcion_token'] ?? null, $data['criterio']]);
            if ($row->decision) {
                abort_unless(json_decode($row->decision, true)['huella'] === $fingerprint, 409, 'La equivalencia ya fue aprobada.');
                return;
            }
            $certificate = DB::table(self::CERTIFICATES)->find($row->certificado_id);
            $intake = app(EnrollmentIntake::class);
            $subject = $intake->resolve('materia', $data['materia_token'], $this->context->subjects($locked));
            $period = $intake->resolve('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $locked->ano_lectivo_id));
            abort_unless($period->fecha_fin->toDateString() < $certificate->fecha_ingreso && $period->id !== $certificate->periodo_ingreso_id,
                422, 'Selecciona un periodo local anterior al ingreso.');
            abort_if(DB::table(self::RESULTS)->where('matricula_id', $locked->id)->where('materia_id', $subject->id)
                ->where('periodo_id', $period->id)->exists(), 409, 'Esta materia y periodo ya tienen una equivalencia aprobada.');
            abort_if(SplitPeriodGrades::active([$locked->id], $subject->id, $period->id)->exists(), 409, 'Este periodo se resuelve mediante la consolidación por cambio de grupo.');
            abort_if(GradeTransferResults::active([$locked->id], $subject->id, $period->id)->whereNotNull('t.resultado')->exists(),
                409, 'El periodo ya tiene una definitiva del grado anterior.');
            $assessed = DB::table(EntryAssessments::TABLE)->where('matricula_id', $locked->id)->where('materia_id', $subject->id)
                ->where('periodo_id', $period->id)->whereNotNull('aprobado_en')->exists();
            $pending = DB::table(PendingCertificates::TABLE)->where('matricula_id', $locked->id);
            $linked = (clone $pending)->where('resultado_id', $row->id)->first();
            abort_if($linked && ($linked->materia_id !== $subject->id || $linked->periodo_id !== $period->id),
                409, 'La equivalencia debe corresponder a la materia y periodo en seguimiento.');
            $target = (clone $pending)->where('materia_id', $subject->id)->where('periodo_id', $period->id)->first();
            abort_if($target?->certificado_revisado_id !== null && (!$assessed || $target->certificado_revisado_id === $row->certificado_id), 409, 'El periodo se resuelve mediante una valoración con soporte.');
            abort_if($target && $target->resultado_id !== null && $target->resultado_id !== $row->id,
                409, 'El periodo está vinculado a otro resultado del certificado.');
            $scale = $this->context->scale($locked);
            abort_unless(hash_equals($scale['version'], $data['version_escala']), 409, 'La escala o el grupo cambió. Actualiza la ficha para revisar el criterio.');
            $grade = $this->context->grade($data, $scale);
            $decision = ['materia' => $subject->nombre, 'periodo' => $period->nombre, ...$grade, 'criterio' => $data['criterio'], 'responsable' => $actor->name,
                'siee' => $scale, 'huella' => $fingerprint];
            DB::table(self::RESULTS)->where('id', $row->id)->update(['periodo_id' => $period->id, 'materia_id' => $subject->id,
                'decision' => json_encode($decision, JSON_THROW_ON_ERROR), 'aprobador_id' => $actor->id, 'aprobado_en' => now()]);
            GradeSourceDecisions::capture(DB::table(self::RESULTS)->find($row->id));
            if ($target && $target->resultado_id === null && $target->certificado_revisado_id === null) {
                DB::table(PendingCertificates::TABLE)->where('id', $target->id)->update(['resultado_id' => $row->id]);
            }
            AuditLogger::tenant($actor, 'UPDATE', 'matricula_equivalencia', (string) $locked->id, null,
                ['resultado' => $token, 'certificado' => $certificate->archivo_token, 'materia' => $subject->nombre,
                    'periodo' => $period->nombre, 'valor' => $decision['valor'], 'version_siee' => $scale['version']], $data['criterio']);
        });
    }
}
