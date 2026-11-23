<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Services\GradebookService;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EnrollmentTrajectory
{
    public const PERMISSION = 'academico.matriculas.corregir';

    public const RECTIFY = 'academico.matriculas.rectificar';

    private function movements(Matricula $enrollment): Builder
    {
        return DB::table('matricula_movimientos')->where('matricula_id', $enrollment->id);
    }

    private function earliest(Matricula $enrollment, AnoLectivo $year): string
    {
        // A planned year may already have confirmed enrollments before the first class.
        return $enrollment->created_at?->timezone(tenant()->zonaHorariaInstitucional())->toDateString() ?? $year->fecha_inicio->toDateString();
    }

    public function details(Matricula $enrollment, User $actor): array
    {
        $history = $this->movements($enrollment)->orderByDesc('version')->get([
            'id', 'version', 'rectifica_version', 'fecha_efectiva', 'origen', 'destino', 'responsable', 'registrado_en', 'motivo',
        ]);
        $evidence = DB::table('matricula_movimiento_soportes')->whereIn('movimiento_id', $history->pluck('id'))->orderBy('id')
            ->get(['movimiento_id', 'archivo_token', 'nombre', 'responsable', 'motivo', 'registrado_en'])->groupBy('movimiento_id');
        $promotions = DB::table(EarlyPromotions::TABLE)->where('matricula_id', $enrollment->id)->whereNotNull('movimiento_id')->pluck('movimiento_id')->flip();
        foreach ($history as $move) {
            $move->promocion_anticipada = $promotions->has($move->id);
            $move->soportes = $evidence->get($move->id, collect())->map(fn ($file) => [
                'token' => $file->archivo_token, 'nombre' => $file->nombre, 'responsable' => $file->responsable,
                'motivo' => $file->motivo, 'registrado_en' => CarbonImmutable::parse($file->registrado_en)->toIso8601String(),
            ])->values();
            unset($move->id);
            $move->registrado_en = CarbonImmutable::parse($move->registrado_en)->toIso8601String();
        }
        $year = $enrollment->anoLectivo;
        $counts = [];
        foreach (['notas' => 'calificaciones', 'asistencias' => 'asistencia_marcas', 'entregas' => 'aula_entregas',
            'recuperaciones' => 'recuperaciones_academicas', 'observaciones' => 'observaciones_boletin', 'boletines' => 'boletin_emisiones'] as $key => $table) {
            $records = DB::table($table)->where('matricula_id', $enrollment->id);
            $counts[$key] = $key === 'notas' ? $records->whereNotNull('valor')->count() : $records->count();
        }
        $counts['resultados_manuales'] = 0;
        foreach (['materia', 'area', 'componente', 'anuales'] as $type) {
            $counts['resultados_manuales'] += DB::table('resultados_manuales_'.$type)->where('matricula_id', $enrollment->id)->count();
        }
        $today = now(tenant()->zonaHorariaInstitucional())->toDateString();

        $open = $enrollment->estado === 'activa' && in_array($year->estado, ['planificado', 'en_curso'], true);
        $canCorrect = $open && $actor->can(self::PERMISSION);
        $canRectify = $open && $actor->can(self::RECTIFY);
        $destinations = ($canCorrect || $canRectify) ? Grupo::with('grado')->where('ano_lectivo_id', $year->id)
            ->where('sede_id', $enrollment->grupo()->withTrashed()->value('sede_id'))->where('estado', 'activo')->whereKeyNot($enrollment->grupo_id)
            ->whereHas('grado', fn ($q) => $q->where('estado', 'activo'))->orderBy('grado_id')->orderBy('nombre')->get()
            ->map(fn ($g) => ['token' => OpaqueUrlToken::for('grupo', $g->id), 'nombre' => $g->grado->nombre.' / '.$g->nombre]) : [];

        return ['movimientos' => $history, 'version' => $history->first()->version ?? 0, 'resumen' => $counts, 'destinos' => $destinations,
            'tiene_resultados_traslado' => $history->isNotEmpty() && GradeTransferResults::active([$enrollment->id])->exists(),
            'grupo_actual' => OpaqueUrlToken::for('grupo', $enrollment->grupo_id),
            'desde' => $history->first()->fecha_efectiva ?? $this->earliest($enrollment, $year),
            'hasta' => min($today, $year->fecha_fin->toDateString()),
            'puede_corregir' => $canCorrect, 'puede_rectificar' => $canRectify,
            'max_soporte_bytes' => (int) config('storage.max_file_bytes'),
            'puede_ver_notas' => app(GradebookService::class)->manages($actor)];
    }

    public function correct(Matricula $candidate, User $actor, array $data): void
    {
        $rectifies = isset($data['rectifica_version']) ? (int) $data['rectifica_version'] : null;
        abort_unless($actor->can($rectifies === null ? self::PERMISSION : self::RECTIFY), 403);
        $this->move($candidate, $actor, $data, $rectifies);
    }

    /** Called inside the council decision transaction, after validating the dossier. */
    public function applyPromotion(Matricula $enrollment, User $actor, array $data): int
    {
        abort_unless($actor->can(EarlyPromotions::DECIDE), 403);
        return $this->move($enrollment, $actor, $data, null);
    }

    private function move(Matricula $candidate, User $actor, array $data, ?int $rectifies): int
    {
        $uploads = EnrollmentMovementEvidence::fingerprint($data['soportes'] ?? []);
        return DB::transaction(function () use ($candidate, $actor, $data, $rectifies, $uploads) {
            // Enrollment, gradebook and attendance writers serialize on the academic year.
            $year = AnoLectivo::lockForUpdate()->findOrFail($candidate->ano_lectivo_id);
            $enrollment = Matricula::lockForUpdate()->findOrFail($candidate->id);
            $last = $this->movements($enrollment)->orderByDesc('version')->first();
            $version = (int) ($last->version ?? 0);
            $expected = (int) $data['version'];
            if ($last && $expected + 1 === $version && $last->actor_id === $actor->id
                && OpaqueUrlToken::for('grupo', $last->grupo_destino_id) === $data['grupo_token']
                && $last->fecha_efectiva === $data['fecha_efectiva'] && $last->motivo === $data['motivo'] && $last->rectifica_version === $rectifies) {
                $original = DB::table('matricula_movimiento_soportes')->where('movimiento_id', $last->id)->where('inicial', true)
                    ->orderBy('huella')->pluck('huella')->all();
                abort_unless($original === array_keys($uploads), 409, 'Los soportes difieren del movimiento registrado.');
                return $last->id;
            }
            abort_unless($version === $expected, 409, 'La trayectoria cambió. Revisa el último movimiento y vuelve a abrir la corrección.');
            abort_unless($enrollment->estado === 'activa' && in_array($year->estado, ['planificado', 'en_curso'], true),
                422, 'Se requiere una matrícula activa y un año en preparación o en curso.');
            if ($rectifies !== null) {
                $originalId = $this->movements($enrollment)->where('version', $rectifies)->value('id');
                abort_unless($originalId, 404);
                abort_if(DB::table(EarlyPromotions::TABLE)->where('movimiento_id', $originalId)->exists(), 422,
                    'La decisión del Consejo Directivo forma parte del expediente de promoción anticipada.');
                abort_if($this->movements($enrollment)->where('rectifica_version', $rectifies)->exists(), 409,
                    'Ese movimiento ya tiene una rectificación. Revisa la actuación posterior.');
            }
            $date = $data['fecha_efectiva'];
            $min = $last->fecha_efectiva ?? $this->earliest($enrollment, $year);
            abort_if($date < $min || $date > min(now(tenant()->zonaHorariaInstitucional())->toDateString(), $year->fecha_fin->toDateString()),
                422, 'La fecha debe estar dentro de la vigencia de la matrícula, desde el último movimiento y hasta hoy.');
            $origin = $enrollment->grupo()->withTrashed()->with(['grado' => fn ($q) => $q->withTrashed()])->firstOrFail();
            $destination = OpaqueUrlToken::find('grupo', $data['grupo_token'], Grupo::with('grado')
                ->where('ano_lectivo_id', $year->id)->where('sede_id', $origin->sede_id)->lockForUpdate());
            abort_unless($destination, 404);
            abort_if($destination->id === $origin->id, 422, 'Selecciona otro grupo de destino.');
            abort_unless($destination->estaActivo() && $destination->grado?->estaActivo(), 422, 'El grado y el grupo de destino deben estar activos.');
            abort_if($destination->cupo_maximo !== null && Matricula::where('grupo_id', $destination->id)
                ->where('estado', 'activa')->count() >= $destination->cupo_maximo, 422, 'El grupo de destino alcanzó su cupo.');
            $originLabel = $origin->grado->nombre.' / '.$origin->nombre;
            $destinationLabel = $destination->grado->nombre.' / '.$destination->nombre;
            $results = app(GradeTransferResults::class)->prepare($enrollment, $origin, $destination, $date);
            $id = $this->movements($enrollment)->insertGetId(['matricula_id' => $enrollment->id, 'version' => $version + 1,
                'rectifica_version' => $rectifies,
                'grupo_origen_id' => $origin->id, 'grupo_destino_id' => $destination->id, 'origen' => $originLabel,
                'destino' => $destinationLabel, 'motivo' => $data['motivo'], 'actor_id' => $actor->id,
                'responsable' => $actor->name, 'registrado_en' => now(), 'fecha_efectiva' => $date]);
            if ($results !== []) {
                DB::table(GradeTransferResults::TABLE)->insert(array_map(fn ($row) => ['movimiento_id' => $id, ...$row], $results));
            }
            EnrollmentMovementEvidence::attach($enrollment, $id, $version + 1, $uploads, $actor, $data['motivo'], true);
            $enrollment->update(['grupo_id' => $destination->id]);
            app(SplitPeriodGrades::class)->moved($enrollment, $destination, $id, $date);
            AuditLogger::tenant($actor, 'UPDATE', 'matricula_trayectoria', (string) $enrollment->id,
                ['grupo' => $originLabel], ['grupo' => $destinationLabel, 'fecha_efectiva' => $date, 'version' => $version + 1,
                    'rectifica_version' => $rectifies, 'periodos_materia_revisados' => count($results)], $data['motivo']);
            return $id;
        });
    }

    public function addEvidence(Matricula $candidate, User $actor, array $data): void
    {
        $uploads = EnrollmentMovementEvidence::fingerprint($data['soportes']);
        DB::transaction(function () use ($candidate, $actor, $data, $uploads) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($candidate->ano_lectivo_id);
            $enrollment = Matricula::lockForUpdate()->findOrFail($candidate->id);
            $move = $this->movements($enrollment)->where('version', $data['movimiento_version'])->firstOrFail();
            abort_unless($actor->can($move->rectifica_version === null ? self::PERMISSION : self::RECTIFY), 403);
            abort_unless($enrollment->estado === 'activa' && in_array($year->estado, ['planificado', 'en_curso'], true),
                422, 'Se requiere una matrícula activa y un año en preparación o en curso.');
            EnrollmentMovementEvidence::attach($enrollment, $move->id, $move->version, $uploads, $actor, $data['motivo'], false);
        });
    }

    public function activityGrades(Matricula $enrollment)
    {
        return DB::table('calificaciones as n')->join('actividades_evaluacion as a', 'a.id', '=', 'n.actividad_id')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')->join('periodos as p', 'p.id', '=', 'c.periodo_id')
            ->join('asignaciones_docentes as d', 'd.id', '=', 'c.asignacion_id')->join('grupos as g', 'g.id', '=', 'd.grupo_id')
            ->join('grados as grado', 'grado.id', '=', 'g.grado_id')->join('materias as m', 'm.id', '=', 'd.materia_id')
            ->leftJoin('escala_opciones as escala', 'escala.id', '=', 'n.escala_opcion_id')
            ->where('n.matricula_id', $enrollment->id)->whereNotNull('n.valor')->orderByDesc('a.fecha')->orderByDesc('n.id')
            ->select(['a.fecha', 'a.nombre as actividad', 'p.nombre as periodo', 'm.nombre as materia',
                'g.nombre as grupo', 'grado.nombre as grado', 'n.valor', 'escala.nombre as categoria'])->simplePaginate(25);
    }
}
