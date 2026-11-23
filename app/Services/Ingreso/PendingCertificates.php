<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PendingCertificates
{
    public const TABLE = 'matricula_certificados_pendientes';

    private static function withResults(): Builder
    {
        return DB::table(self::TABLE.' as p')->leftJoin('matricula_definitivas_externas as r', 'r.id', '=', 'p.resultado_id')
            ->leftJoin(EntryAssessments::TABLE.' as v', fn ($join) => $join->whereNotNull('p.certificado_revisado_id')
                ->on('v.matricula_id', '=', 'p.matricula_id')->on('v.materia_id', '=', 'p.materia_id')->on('v.periodo_id', '=', 'p.periodo_id'));
    }

    public static function open(iterable $enrollments, ?int $subject = null, ?int $period = null): Builder
    {
        return self::withResults()->whereNull('v.aprobado_en')
            ->whereIn('p.matricula_id', collect($enrollments))->whereNull('r.aprobado_en')
            ->when($subject !== null, fn ($q) => $q->where('p.materia_id', $subject))
            ->when($period !== null, fn ($q) => $q->where('p.periodo_id', $period))->select('p.*');
    }

    public static function owners(User $actor, string $permission = CertifiedExternalGrades::REGISTER): \Illuminate\Database\Eloquent\Builder
    {
        return User::where('status', User::STATUS_ACTIVE)->where(function ($q) use ($actor, $permission) {
            $q->permission($permission)->permission('academico.matriculas.gestionar');
            if ($actor->can($permission)) {
                $q->orWhere('id', $actor->id);
            }
        });
    }

    public static function details(Matricula $enrollment): Collection
    {
        return self::withResults()->where('p.matricula_id', $enrollment->id)
            ->join('materias as m', 'm.id', '=', 'p.materia_id')->join('periodos as t', 't.id', '=', 'p.periodo_id')
            ->leftJoin(CertifiedExternalGrades::CERTIFICATES.' as c', 'c.id', '=', 'p.certificado_revisado_id')
            ->orderBy('m.nombre')->orderBy('t.orden')->get(['p.*','m.nombre as materia','t.nombre as periodo',
                'r.token as resultado_token','r.aprobado_en','r.decision', 'c.archivo_token as certificado_revisado',
                'v.token as valoracion_token','v.aprobado_en as valoracion_aprobada','v.decision as decision_valoracion'])->map(fn ($row) => [
                    'token' => $row->token, 'materia' => $row->materia, 'periodo' => $row->periodo,
                    'materia_token' => OpaqueUrlToken::for('materia', $row->materia_id),
                    'periodo_token' => OpaqueUrlToken::for('periodo', $row->periodo_id),
                    'fecha_seguimiento' => $row->fecha_seguimiento, 'responsable' => $row->responsable,
                    'resultado_token' => $row->resultado_token, 'certificado_revisado' => $row->certificado_revisado,
                    'motivo_sin_equivalencia' => $row->motivo_sin_equivalencia, 'revisado_en' => $row->revisado_en,
                    'valoracion_token' => $row->valoracion_token, 'aprobado_en' => $row->aprobado_en ?? $row->valoracion_aprobada,
                    'aprobado_por' => json_decode($row->decision ?? $row->decision_valoracion ?? 'null', true, flags: JSON_THROW_ON_ERROR)['responsable'] ?? null,
                ]);
    }

    public function register(Matricula $enrollment, array $data, User $actor, ?string $reviewedFile = null): void
    {
        DB::transaction(function () use ($enrollment, $data, $actor, $reviewedFile) {
            $context = app(EnrollmentGradeContext::class);
            $student = $context->lockEnrollment($enrollment);
            $intake = app(EnrollmentIntake::class);
            $subject = $intake->resolve('materia', $data['materia_token'], $context->subjects($student));
            $certificate = $reviewedFile === null ? null : DB::table(CertifiedExternalGrades::CERTIFICATES)
                ->where('matricula_id', $student->id)->where('archivo_token', $reviewedFile)->firstOrFail();
            $owner = $intake->resolve('usuario', $data['responsable_token'], self::owners($actor,
                $certificate ? EntryAssessments::REGISTER : CertifiedExternalGrades::REGISTER));
            $date = $certificate->fecha_ingreso ?? $data['fecha_ingreso'];
            $known = $context->entryDate($student);
            abort_if($known && $known !== $date, 422, 'La fecha de ingreso debe coincidir con el expediente.');
            $periods = Periodo::where('ano_lectivo_id', $student->ano_lectivo_id);
            abort_if($date > now(tenant()->zonaHorariaInstitucional())->toDateString()
                || !(clone $periods)->whereDate('fecha_inicio', '<=', $date)->whereDate('fecha_fin', '>=', $date)->exists(),
                422, 'La fecha de ingreso debe pertenecer a un periodo del año y no puede ser futura.');
            abort_if($data['fecha_seguimiento'] < $date, 422, 'El seguimiento debe ser el día del ingreso o después.');
            foreach ($data['periodos'] as $token) {
                $period = $intake->resolve('periodo', $token, clone $periods);
                abort_if($period->fecha_fin->toDateString() >= $date, 422, 'Elige periodos anteriores al ingreso.');
                $key = ['matricula_id' => $student->id,'materia_id' => $subject->id,'periodo_id' => $period->id];
                $existing = DB::table(self::TABLE)->where($key)->first();
                if ($existing && (! $certificate || $existing->certificado_revisado_id !== null)) {
                    abort_unless($existing->certificado_revisado_id === $certificate?->id &&
                        $existing->motivo_sin_equivalencia === ($data['motivo'] ?? null), 409, 'El seguimiento ya tiene una decisión diferente.');
                    abort_unless($owner->id === $existing->responsable_id && $data['fecha_seguimiento'] === $existing->fecha_seguimiento,
                        409, 'Ya existe un seguimiento para esta materia y periodo.');
                    continue;
                }
                abort_if($existing?->resultado_id !== null, 409, 'Este periodo ya tiene un resultado certificado para revisar.');
                abort_if(app(EntryAssessments::class)->priorGrades($student)->where('materia_id', $subject->id)->where('periodo_id', $period->id)->exists()
                    || DB::table(EntryAssessments::TABLE)->where($key)->exists()
                    || SplitPeriodGrades::active([$student->id], $subject->id, $period->id)->exists()
                    || GradeTransferResults::active([$student->id], $subject->id, $period->id)->whereNotNull('t.resultado')->exists(),
                    409, 'Ya existen notas o una actuación académica para esta materia y periodo.');
                $pending = $existing->token ?? Str::random(24);
                $followUp = ['responsable_id' => $owner->id, 'responsable' => $owner->name, 'fecha_seguimiento' => $data['fecha_seguimiento']];
                if ($certificate) {
                    $followUp += ['certificado_revisado_id' => $certificate->id, 'motivo_sin_equivalencia' => $data['motivo'],
                        'revisado_en' => now(), 'revisor_id' => $actor->id];
                }
                if ($existing) {
                    DB::table(self::TABLE)->where('id', $existing->id)->update($followUp);
                } else {
                    DB::table(self::TABLE)->insert([...$key, ...$followUp, 'token' => $pending, 'fecha_ingreso' => $date,
                        'registrador_id' => $actor->id, 'registrado_en' => now()]);
                }
                AuditLogger::tenant($actor, $existing ? 'UPDATE' : 'CREATE', $certificate ? 'matricula_sin_equivalencia' : 'matricula_certificado_pendiente',
                    (string) $student->id, $existing ? ['responsable' => $existing->responsable, 'fecha_seguimiento' => $existing->fecha_seguimiento] : null,
                    ['pendiente' => $pending, 'materia' => $subject->nombre, 'periodo' => $period->nombre,
                        'responsable' => $owner->name, 'fecha_seguimiento' => $data['fecha_seguimiento'], 'certificado' => $reviewedFile], $data['motivo'] ?? null);
            }
        });
    }
}
