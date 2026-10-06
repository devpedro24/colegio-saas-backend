<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\SesionHorario;
use App\Models\Plan;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\StudentRosterName;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Una asistencia por fecha y franja explícita; las instantáneas nunca siguen las ediciones del horario. */
final class AsistenciaController extends Controller
{
    private const DAYS = [1 => 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

    private function plan(): void
    {
        $features = Plan::where('key', tenant()->plan)->value('features') ?? [];
        abort_unless(in_array('asistencia', $features, true), 403,
            'El plan del colegio no incluye asistencia.');
    }

    private function broad(User $actor): bool
    {
        return $actor->can('asistencia.consultar_grupo')
            && ($actor->hasAnyRole(['rector', 'coord_academico', 'coord_combinado'])
                || $actor->esSuperadminPlataforma());
    }

    private function assignment(User $actor, string $token, bool $write): AsignacionDocente
    {
        $assignment = OpaqueUrlToken::find('asignacion-docente', $token, AsignacionDocente::query());
        abort_unless($assignment, 404);
        $own = $assignment->docente_id === $actor->id;
        abort_unless($write
            ? $actor->can('asistencia.registrar_clases') && ($own || $this->broad($actor))
            : ($own && $actor->can('asistencia.registrar_clases')) || $this->broad($actor), 403);

        return $assignment;
    }

    private function presentPolicy(?object $policy): array
    {
        return [
            'max_faltas' => $policy?->max_faltas,
            'max_porcentaje' => $policy?->max_porcentaje,
            'combinacion' => $policy?->combinacion ?? 'cualquiera',
            'ambito' => $policy?->ambito ?? 'periodo',
            'tardes_por_falta' => $policy?->tardes_por_falta ?? 0,
            'version' => $policy?->version ?? 0,
            'consecuencia' => 'solo_alerta',
        ];
    }

    public function policy(Request $request, int $ano): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($this->broad($actor) || ($actor->can('asistencia.registrar_clases')
            && AsignacionDocente::where('ano_lectivo_id', $ano)->where('docente_id', $actor->id)->exists()), 403);
        AnoLectivo::findOrFail($ano);

        return response()->json(['data' => $this->presentPolicy(
            DB::table('asistencia_politicas')->where('ano_lectivo_id', $ano)->first(),
        )]);
    }

    public function savePolicy(Request $request, int $ano): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($actor->can('academico.configurar')
            && ($actor->hasAnyRole(['rector', 'coord_academico', 'coord_combinado'])
                || $actor->esSuperadminPlataforma()), 403);
        $data = $request->validate([
            'max_faltas' => ['present', 'nullable', 'integer', 'min:0', 'max:1000'],
            'max_porcentaje' => ['present', 'nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'combinacion' => ['required', Rule::in(['cualquiera', 'ambos'])],
            'ambito' => ['required', Rule::in(['periodo', 'anual'])],
            'tardes_por_falta' => ['required', 'integer', 'min:0', 'max:100'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $result = DB::transaction(function () use ($ano, $actor, $data) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($ano);
            abort_if($year->estaCerrado(), 422,
                'No se cambia la política de asistencia de un año cerrado.');
            $before = DB::table('asistencia_politicas')->where('ano_lectivo_id', $ano)->lockForUpdate()->first();
            abort_unless((int) ($before?->version ?? 0) === $data['version'], 409,
                'La política cambió. Recarga antes de guardar.');
            $values = [
                'max_faltas' => $data['max_faltas'], 'max_porcentaje' => $data['max_porcentaje'],
                'combinacion' => $data['combinacion'], 'ambito' => $data['ambito'],
                'tardes_por_falta' => $data['tardes_por_falta'],
                'version' => $data['version'] + 1, 'updated_at' => now(),
            ];
            if ($before) {
                DB::table('asistencia_politicas')->where('id', $before->id)->update($values);
                $id = $before->id;
            } else {
                $id = DB::table('asistencia_politicas')->insertGetId([
                    'ano_lectivo_id' => $ano, ...$values, 'created_at' => now(),
                ]);
            }
            AuditLogger::tenant($actor, $before ? 'UPDATE' : 'CREATE', 'asistencia_politica',
                (string) $id, $before ? (array) $before : null, $values);

            return (object) $values;
        });

        return response()->json(['data' => $this->presentPolicy($result)]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($actor->can('asistencia.registrar_clases') || $this->broad($actor), 403);
        $data = $request->validate([
            'ano_lectivo_token' => ['nullable', 'string'],
            'asignacion_token' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $year = isset($data['ano_lectivo_token'])
            ? OpaqueUrlToken::find('ano-lectivo', $data['ano_lectivo_token'], AnoLectivo::query()) : null;
        abort_if(isset($data['ano_lectivo_token']) && ! $year, 404);
        if (empty($data['asignacion_token']) || empty($data['fecha'])) {
            $assignments = AsignacionDocente::with(['materia:id,nombre', 'grupo.grado:id,nombre'])
                ->when($year, fn ($q) => $q->where('ano_lectivo_id', $year->id))
                ->when(! $this->broad($actor), fn ($q) => $q->where('docente_id', $actor->id))
                ->orderBy('grupo_id')->orderBy('materia_id')->limit(500)->get();

            return response()->json(['data' => ['asignaciones' => $assignments->map(fn (AsignacionDocente $row) => [
                'token' => OpaqueUrlToken::for('asignacion-docente', $row->id),
                'nombre' => $row->grupo->grado->nombre.' / ('.$row->grupo->nombre.') · '.$row->materia->nombre,
                'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $row->ano_lectivo_id),
            ])]]);
        }

        $result = [];
        $assignment = $this->assignment($actor, $data['asignacion_token'], false);
        abort_if($year && $year->id !== $assignment->ano_lectivo_id, 422);
        [$date, $period] = $this->dateAndPeriod($assignment, $data['fecha']);
        $weekday = self::DAYS[$date->dayOfWeekIso];
        $sessions = SesionHorario::where('asignacion_id', $assignment->id)->where('dia', $weekday)
            ->with('bloque')->orderBy('id')->get();
        $records = DB::table('asistencia_clases')->where('asignacion_id', $assignment->id)
            ->where('fecha', $data['fecha'])->get()->keyBy('sesion_horario_id');
        $ids = $sessions->pluck('id')->merge($records->keys())->unique();
        $allSessions = SesionHorario::withTrashed()->with('bloque')->whereIn('id', $ids)->get()->keyBy('id');
        $roster = Matricula::with('estudiante:id,name')->where('grupo_id', $assignment->grupo_id)
            ->where('ano_lectivo_id', $assignment->ano_lectivo_id)->where('estado', 'activa')->get();
        $marks = DB::table('asistencia_marcas')->whereIn('asistencia_clase_id', $records->pluck('id'))->get()
            ->groupBy('asistencia_clase_id');
        $policy = DB::table('asistencia_politicas')->where('ano_lectivo_id', $assignment->ano_lectivo_id)->first();
        $summaryRows = DB::table('asistencia_marcas as m')
            ->join('asistencia_clases as c', 'c.id', '=', 'm.asistencia_clase_id')
            ->where('c.asignacion_id', $assignment->id)->where('c.fecha', '<=', $data['fecha'])
            ->when(($policy?->ambito ?? 'periodo') === 'periodo', fn ($q) => $q->where('c.periodo_id', $period->id))
            ->selectRaw("m.matricula_id, count(*) as total, sum(case when m.estado = 'ausente' then 1 else 0 end) as faltas, sum(case when m.estado = 'tarde' then 1 else 0 end) as tardes")
            ->groupBy('m.matricula_id')->get()->keyBy('matricula_id');
        $summary = function (int $enrollmentId) use ($summaryRows, $policy): array {
            $row = $summaryRows->get($enrollmentId);
            $total = (int) ($row?->total ?? 0);
            $late = (int) ($row?->tardes ?? 0);
            $absent = (int) ($row?->faltas ?? 0);
            $equivalent = $absent + ($policy && $policy->tardes_por_falta > 0
                ? intdiv($late, $policy->tardes_por_falta) : 0);
            $percentageExact = $total > 0 ? 100 * $equivalent / $total : 0;
            $byCount = $policy?->max_faltas !== null && $equivalent > $policy->max_faltas;
            $byPercentage = $policy?->max_porcentaje !== null
                && $percentageExact > (float) $policy->max_porcentaje;

            return ['registradas' => $total, 'ausentes' => $absent, 'tardes' => $late,
                'faltas_equivalentes' => $equivalent, 'porcentaje' => round($percentageExact, 2),
                'alerta' => $policy?->combinacion === 'ambos'
                    ? $byCount && $byPercentage : $byCount || $byPercentage];
        };
        $formerEnrollments = Matricula::with('estudiante:id,name')->whereIn('id', $marks->flatten(1)->pluck('matricula_id'))
            ->get()->keyBy('id');
        $result['periodo'] = ['nombre' => $period->nombre, 'estado' => $period->estado];
        $result['clases'] = $ids->map(function ($id) use ($allSessions, $records, $marks, $roster, $formerEnrollments, $actor, $assignment, $period, $summary) {
            $session = $allSessions->get($id);
            $record = $records->get($id);
            $start = $record?->hora_inicio ?? $session?->bloque?->hora_inicio ?? $session?->hora_inicio;
            $end = $record?->hora_fin ?? $session?->bloque?->hora_fin ?? $session?->hora_fin;
            $students = $record ? ($marks->get($record->id) ?? collect())->map(fn ($mark) => [
                'matricula_token' => OpaqueUrlToken::for('matricula', $mark->matricula_id),
                'nombre' => StudentRosterName::display($formerEnrollments->get($mark->matricula_id)?->estudiante?->name ?? 'Estudiante no disponible'),
                'estado' => $mark->estado,
                'resumen' => $summary($mark->matricula_id),
            ]) : $roster->map(fn (Matricula $row) => [
                'matricula_token' => OpaqueUrlToken::for('matricula', $row->id),
                'nombre' => StudentRosterName::display($row->estudiante?->name ?? 'Estudiante no disponible'),
                'estado' => null,
                'resumen' => $summary($row->id),
            ]);

            return [
                'sesion_token' => OpaqueUrlToken::for('sesion-horario', $id),
                'hora_inicio' => substr((string) $start, 0, 5), 'hora_fin' => substr((string) $end, 0, 5),
                'registrada' => $record !== null,
                'version' => (int) ($record?->version ?? 0),
                'historica' => $record !== null && ($session?->trashed() || $session?->asignacion_id !== $record->asignacion_id
                    || $session?->dia !== self::DAYS[CarbonImmutable::parse($record->fecha)->dayOfWeekIso]
                    || substr((string) ($session?->bloque?->hora_inicio ?? $session?->hora_inicio), 0, 5) !== substr((string) $record->hora_inicio, 0, 5)),
                'editable' => $this->mayWrite($actor, $assignment) && $period->estado === 'abierto'
                    && $assignment->anoLectivo?->estado === 'en_curso',
                'estudiantes' => $students->sortBy(fn ($student) => StudentRosterName::sortKey($student['nombre']))->values(),
            ];
        })->sortBy('hora_inicio')->values();

        return response()->json(['data' => $result]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->plan();
        $data = $request->validate([
            'asignacion_token' => ['required', 'string'],
            'sesion_token' => ['required', 'string'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'version' => ['required', 'integer', 'min:0'],
            'marcas' => ['required', 'array', 'min:1', 'max:100'],
            'marcas.*.matricula_token' => ['required', 'string', 'distinct'],
            'marcas.*.estado' => ['required', Rule::in(['presente', 'ausente', 'tarde'])],
        ]);
        $actor = $request->user();
        $assignment = $this->assignment($actor, $data['asignacion_token'], true);
        $result = DB::transaction(function () use ($actor, $assignment, $data) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($assignment->ano_lectivo_id);
            [$date, $period] = $this->dateAndPeriod($assignment, $data['fecha']);
            $period = Periodo::lockForUpdate()->findOrFail($period->id);
            abort_unless($year->estado === 'en_curso' && $period->estado === 'abierto', 422,
                'Solo se registra asistencia en un período abierto del año en curso.');
            $session = OpaqueUrlToken::find('sesion-horario', $data['sesion_token'], SesionHorario::withTrashed());
            abort_unless($session, 404);
            $record = DB::table('asistencia_clases')->where('sesion_horario_id', $session->id)
                ->where('fecha', $data['fecha'])->lockForUpdate()->first();
            abort_unless((int) ($record?->version ?? 0) === $data['version'], 409,
                'La asistencia cambió desde que la abriste. Recarga antes de guardar.');
            if ($record) {
                abort_unless($record->asignacion_id === $assignment->id && $record->periodo_id === $period->id, 422,
                    'La asistencia histórica pertenece a otra asignación o período.');
            } else {
                abort_unless(! $session->trashed() && $session->asignacion_id === $assignment->id
                    && $session->grupo_id === $assignment->grupo_id && $session->materia_id === $assignment->materia_id
                    && $session->dia === self::DAYS[$date->dayOfWeekIso], 422,
                    'La franja no pertenece a esta asignación en la fecha seleccionada.');
            }
            $existing = $record ? DB::table('asistencia_marcas')->where('asistencia_clase_id', $record->id)
                ->lockForUpdate()->get()->keyBy('matricula_id') : collect();
            $rosterIds = $record ? $existing->keys()->all() : Matricula::where('grupo_id', $assignment->grupo_id)
                ->where('ano_lectivo_id', $assignment->ano_lectivo_id)->where('estado', 'activa')->pluck('id')->all();
            $tokens = array_column($data['marcas'], 'matricula_token');
            $ids = OpaqueUrlToken::ids('matricula', $tokens, Matricula::whereIn('id', $rosterIds));
            abort_unless(count($ids) === count($rosterIds) && count($ids) === count($tokens), 422,
                'Registra exactamente una marca por estudiante de esta clase.');
            if (! $record) {
                $start = $session->bloque?->hora_inicio ?? $session->hora_inicio;
                $end = $session->bloque?->hora_fin ?? $session->hora_fin;
                $recordId = DB::table('asistencia_clases')->insertGetId([
                    'sesion_horario_id' => $session->id, 'ano_lectivo_id' => $assignment->ano_lectivo_id,
                    'periodo_id' => $period->id, 'asignacion_id' => $assignment->id,
                    'grupo_id' => $assignment->grupo_id, 'materia_id' => $assignment->materia_id,
                    'docente_programado_id' => $session->docente_id, 'espacio_fisico_id' => $session->espacio_fisico_id,
                    'fecha' => $data['fecha'], 'hora_inicio' => $start, 'hora_fin' => $end,
                    'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                AuditLogger::tenant($actor, 'CREATE', 'asistencia_clase', (string) $recordId, null,
                    ['fecha' => $data['fecha'], 'asignacion_id' => $assignment->id, 'sesion_horario_id' => $session->id]);
            } else {
                $recordId = $record->id;
            }
            $changed = false;
            foreach ($data['marcas'] as $item) {
                $enrollmentId = $ids[$item['matricula_token']];
                $before = $existing->get($enrollmentId);
                if ($before?->estado === $item['estado']) {
                    continue;
                }
                $changed = true;
                if ($before) {
                    DB::table('asistencia_marcas')->where('id', $before->id)->update([
                        'estado' => $item['estado'], 'registrado_por_id' => $actor->id, 'updated_at' => now(),
                    ]);
                    $markId = $before->id;
                } else {
                    $markId = DB::table('asistencia_marcas')->insertGetId([
                        'asistencia_clase_id' => $recordId, 'matricula_id' => $enrollmentId,
                        'estado' => $item['estado'], 'registrado_por_id' => $actor->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                AuditLogger::tenant($actor, $before ? 'UPDATE' : 'CREATE', 'asistencia_marca',
                    (string) $markId,
                    $before ? ['estado' => $before->estado] : null,
                    ['estado' => $item['estado'], 'matricula_id' => $enrollmentId, 'asistencia_clase_id' => $recordId]);
            }
            if ($record && $changed) {
                DB::table('asistencia_clases')->where('id', $recordId)->update([
                    'version' => $record->version + 1, 'updated_at' => now(),
                ]);
            }

            return ['registrada' => true, 'version' => $record ? $record->version + (int) $changed : 1,
                'estudiantes' => count($rosterIds)];
        });

        return response()->json(['data' => $result]);
    }

    private function mayWrite(User $actor, AsignacionDocente $assignment): bool
    {
        return $actor->can('asistencia.registrar_clases')
            && ($assignment->docente_id === $actor->id || $this->broad($actor));
    }

    private function dateAndPeriod(AsignacionDocente $assignment, string $value): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        $year = AnoLectivo::findOrFail($assignment->ano_lectivo_id);
        abort_unless($date && $date->betweenIncluded($year->fecha_inicio, $year->fecha_fin), 422,
            'La fecha no pertenece al año lectivo.');
        $period = Periodo::where('ano_lectivo_id', $year->id)->whereDate('fecha_inicio', '<=', $value)
            ->whereDate('fecha_fin', '>=', $value)->first();
        abort_unless($period, 422, 'La fecha no pertenece a ningún período configurado.');

        return [$date, $period];
    }
}
