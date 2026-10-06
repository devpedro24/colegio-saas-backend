<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\SesionHorario;
use App\Support\OpaqueUrlToken;
use App\Support\StudentRosterName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Proyección del horario por período; solo las ocurrencias guardadas son hechos históricos. */
final class AttendanceSheetService
{
    private const DAYS = [1 => 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

    public function build(AsignacionDocente $assignment, Periodo $period, bool $canWrite): array
    {
        $today = CarbonImmutable::now('America/Bogota')->toDateString();
        $start = CarbonImmutable::parse($period->fecha_inicio)->startOfDay();
        $end = CarbonImmutable::parse($period->fecha_fin)->startOfDay();
        $sessions = SesionHorario::where('asignacion_id', $assignment->id)->with('bloque')->get();
        $byDay = $sessions->groupBy('dia');
        $records = DB::table('asistencia_clases')->where('asignacion_id', $assignment->id)
            ->whereBetween('fecha', [$start->toDateString(), $end->toDateString()])->get();
        $recordsByKey = $records->keyBy(fn ($row) => $row->fecha.':'.$row->sesion_horario_id);
        $allSessions = SesionHorario::withTrashed()->with('bloque')
            ->whereIn('id', $sessions->pluck('id')->merge($records->pluck('sesion_horario_id'))->unique())
            ->get()->keyBy('id');
        $marks = DB::table('asistencia_marcas')
            ->whereIn('asistencia_clase_id', $records->pluck('id'))->get()->groupBy('asistencia_clase_id');
        $current = Matricula::where('ano_lectivo_id', $assignment->ano_lectivo_id)
            ->where('grupo_id', $assignment->grupo_id)->where('estado', 'activa')->pluck('id');
        $activeIds = $current->all();
        $enrollments = Matricula::with('estudiante:id,name')
            ->whereIn('id', $current->merge($marks->flatten(1)->pluck('matricula_id'))->unique())
            ->get()->keyBy('id');
        $policy = DB::table('asistencia_politicas')
            ->where('ano_lectivo_id', $assignment->ano_lectivo_id)->first();
        $totals = DB::table('asistencia_marcas as m')
            ->join('asistencia_clases as c', 'c.id', '=', 'm.asistencia_clase_id')
            ->where('c.asignacion_id', $assignment->id)->where('c.fecha', '<=', $today)
            ->where('m.estado', '!=', 'sin_marcar')
            ->when(($policy?->ambito ?? 'periodo') === 'periodo',
                fn ($query) => $query->where('c.periodo_id', $period->id))
            ->selectRaw("m.matricula_id, count(*) as total, sum(case when m.estado = 'ausente' then 1 else 0 end) as faltas, sum(case when m.estado = 'tarde' then 1 else 0 end) as tardes, sum(case when m.estado = 'justificada' then 1 else 0 end) as justificadas")
            ->groupBy('m.matricula_id')->get()->keyBy('matricula_id');

        $students = $enrollments->map(function (Matricula $row) use ($activeIds, $totals, $policy) {
            $total = $totals->get($row->id);
            $recorded = (int) ($total?->total ?? 0);
            $absent = (int) ($total?->faltas ?? 0);
            $late = (int) ($total?->tardes ?? 0);
            $equivalent = $absent + ($policy && $policy->tardes_por_falta > 0
                ? intdiv($late, $policy->tardes_por_falta) : 0);
            $percentage = $recorded > 0 ? 100 * $equivalent / $recorded : 0;
            $byCount = $policy?->max_faltas !== null && $equivalent > 0
                && $equivalent >= $policy->max_faltas;
            $byPercentage = $policy?->max_porcentaje !== null && $equivalent > 0
                && $percentage >= (float) $policy->max_porcentaje;

            return [
                'matricula_token' => OpaqueUrlToken::for('matricula', $row->id),
                'nombre' => StudentRosterName::display($row->estudiante?->name ?? 'Estudiante no disponible'),
                'orden' => StudentRosterName::sortKey($row->estudiante?->name ?? 'Estudiante no disponible'),
                'activa' => in_array($row->id, $activeIds, true),
                'resumen' => [
                    'registradas' => $recorded, 'ausentes' => $absent, 'tardes' => $late,
                    'justificadas' => (int) ($total?->justificadas ?? 0),
                    'faltas_equivalentes' => $equivalent, 'porcentaje' => round($percentage, 2),
                    'alerta' => $policy?->combinacion === 'ambos'
                        ? $byCount && $byPercentage : $byCount || $byPercentage,
                ],
            ];
        })->sortBy('orden')->values()->map(function (array $row) {
            unset($row['orden']);
            return $row;
        })->all();

        $columns = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $day = self::DAYS[$date->dayOfWeekIso];
            foreach ($byDay->get($day, collect()) as $session) {
                $dateText = $date->toDateString();
                $columns[$dateText.':'.$session->id] = [$dateText, $session->id];
            }
        }
        // Los cambios de horario no borran las columnas ya registradas.
        foreach ($records as $record) {
            $columns[$record->fecha.':'.$record->sesion_horario_id] = [$record->fecha, $record->sesion_horario_id];
        }
        $rows = collect($columns)->map(function (array $column) use (
            $recordsByKey, $allSessions, $marks, $activeIds, $period, $assignment, $canWrite, $today
        ) {
            [$date, $sessionId] = $column;
            $record = $recordsByKey->get($date.':'.$sessionId);
            $session = $allSessions->get($sessionId);
            $startTime = $record?->hora_inicio ?? $session?->bloque?->hora_inicio ?? $session?->hora_inicio;
            $endTime = $record?->hora_fin ?? $session?->bloque?->hora_fin ?? $session?->hora_fin;
            $savedMarks = $record ? ($marks->get($record->id) ?? collect()) : collect();
            $rosterIds = $record ? $savedMarks->pluck('matricula_id')->all() : $activeIds;
            $historic = $record && (! $session || $session->trashed()
                || $session->asignacion_id !== $assignment->id
                || $session->dia !== self::DAYS[CarbonImmutable::parse($date)->dayOfWeekIso]
                || substr((string) ($session->bloque?->hora_inicio ?? $session->hora_inicio), 0, 5)
                    !== substr((string) $record->hora_inicio, 0, 5));

            return [
                'sesion_token' => OpaqueUrlToken::for('sesion-horario', $sessionId),
                'fecha' => $date,
                'hora_inicio' => substr((string) $startTime, 0, 5),
                'hora_fin' => substr((string) $endTime, 0, 5),
                'registrada' => $record !== null,
                'version' => (int) ($record?->version ?? 0),
                'historica' => (bool) $historic,
                'editable' => $canWrite && $period->estado === 'abierto'
                    && $assignment->anoLectivo?->estado === 'en_curso' && $date <= $today,
                'matriculas' => array_map(fn ($id) => OpaqueUrlToken::for('matricula', $id), $rosterIds),
                'marcas' => $savedMarks->mapWithKeys(fn ($mark) => [
                    OpaqueUrlToken::for('matricula', $mark->matricula_id) => $mark->estado,
                ])->all(),
            ];
        })->sortBy(fn (array $row) => $row['fecha'].' '.$row['hora_inicio'].' '.$row['sesion_token'])
            ->values()->all();

        return ['estudiantes' => $students, 'columnas' => $rows];
    }
}
