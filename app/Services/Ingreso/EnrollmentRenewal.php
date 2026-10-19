<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Matricula;
use App\Models\Academico\PromocionAcademica;
use App\Models\Ingreso\Solicitud;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A verified private-link session may reuse an existing student only by matching email and identity. */
final class EnrollmentRenewal
{
    public function find(Solicitud $application, string $type, string $number, bool $alignGrade = false): ?User
    {
        $number = mb_strtoupper(trim($number));
        $student = User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($application->email)])->first();
        $profile = DB::table('ingreso_perfiles')->where('tipo_documento', $type)
            ->where('numero_documento', $number)->first();
        if (! $student && ! $profile) {
            return null;
        }
        if (! $student || ! $profile || (int) $profile->estudiante_id !== $student->id
            || $student->trashed() || $student->status !== User::STATUS_ACTIVE
            || $student->role !== 'estudiante' || ! $student->hasRole('estudiante')) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('La identidad no corresponde al correo verificado o la cuenta no está activa. Contacta al colegio.')]);
        }
        $targetYear = AnoLectivo::findOrFail($application->campana->ano_lectivo_id);
        if (Matricula::where('estudiante_id', $student->id)->where('ano_lectivo_id', $targetYear->id)->exists()) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('El estudiante ya tiene matrícula en este año lectivo.')]);
        }
        if (Solicitud::where('estudiante_id', $student->id)->where('id', '<>', $application->id)
            ->whereNotIn('estado', ['rechazada'])
            ->whereHas('campana', fn ($q) => $q->where('ano_lectivo_id', $targetYear->id))->exists()) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('Ya hay otra solicitud de renovación para este año lectivo.')]);
        }
        $previous = Matricula::with('anoLectivo', 'grupo.grado')
            ->where('estudiante_id', $student->id)
            ->whereHas('anoLectivo', fn ($q) => $q->where('fecha_inicio', '<', $targetYear->fecha_inicio))
            ->get()->sortByDesc(fn ($m) => $m->anoLectivo->fecha_inicio)->first();
        if (! $previous || $previous->estado !== 'activa') {
            throw ValidationException::withMessages(['datos.numero_documento' => __('No hay una matrícula anterior activa para renovar. Contacta al colegio.')]);
        }
        $decision = PromocionAcademica::where('matricula_id', $previous->id)->first();
        if (! $decision || ! $decision->approved_at || ! in_array($decision->resultado, ['promovido', 'reprobado'], true)
            || ! in_array($previous->anoLectivo->estado, [AnoLectivo::ESTADO_CERRADO, AnoLectivo::ESTADO_ARCHIVADO], true)) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('La promoción anterior aún no tiene una decisión académica vigente.')]);
        }
        $reference = $decision->resultado === 'promovido'
            ? Grado::find($decision->grado_destino_id)?->ingreso_grado_id
            : $previous->grupo?->grado?->ingreso_grado_id;
        $matches = Grado::where('ano_lectivo_id', $targetYear->id)->where('ingreso_grado_id', $reference)
            ->where('estado', 'activo')->get()->filter(fn ($grade) => collect($application->campana->configuracion['grados'])
                ->contains('token', OpaqueUrlToken::for('grado', $grade->id)));
        if (! $reference || $matches->count() !== 1) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('La convocatoria no tiene un grado destino único para esta promoción. Contacta al colegio.')]);
        }
        $expected = $matches->first();
        if (($application->grado_aprobado_id ?? $application->grado_id) !== $expected->id) {
            if (! $alignGrade || $application->estado !== 'borrador' || $application->enviada_en || $application->documentos()->exists()) {
                throw ValidationException::withMessages(['datos.numero_documento' => __('El grado solicitado no corresponde al resultado académico anterior. Contacta al colegio.')]);
            }
            $application->update(['grado_id' => $expected->id]);
        }

        return $student;
    }

    public function prefill(Solicitud $application, string $type, string $number): array
    {
        $student = $this->find($application, $type, $number, true);
        if (! $student) {
            return ['encontrado' => false];
        }
        $profile = DB::table('ingreso_perfiles')->where('estudiante_id', $student->id)->first();
        $data = json_decode($profile->datos, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw ValidationException::withMessages(['datos.numero_documento' => __('El expediente anterior necesita conciliación del colegio.')]);
        }
        $allowed = array_column($application->campana->configuracion['campos'], 'key');
        $data['adicionales'] = array_intersect_key($data['adicionales'] ?? [], array_flip($allowed));

        return ['encontrado' => true, 'grado_token' => OpaqueUrlToken::for('grado', $application->grado_id), 'datos' => $data];
    }
}
