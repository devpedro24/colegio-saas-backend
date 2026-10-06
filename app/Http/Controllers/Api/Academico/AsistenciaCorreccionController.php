<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsistenciaSolicitud;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\Academico\SesionHorario;
use App\Models\Plan;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\StudentRosterName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Las faltas nunca se borran: una aprobación cambia su estado y conserva el antes/después auditado. */
final class AsistenciaCorreccionController extends Controller
{
    use PaginatesRequests;

    private function plan(): void
    {
        $features = Plan::where('key', tenant()->plan)->value('features') ?? [];
        abort_unless(in_array('asistencia', $features, true), 403);
    }

    private function student(User $actor): bool
    {
        return $actor->hasRole('estudiante') && $actor->can('asistencia.justificar_propia');
    }

    private function teacher(User $actor): bool
    {
        return $actor->hasAnyRole(['docente', 'director_grupo', 'rector', 'coord_academico', 'coord_combinado'])
            && $actor->can('asistencia.correccion.solicitar');
    }

    private function approver(User $actor): bool
    {
        return $actor->hasAnyRole(['rector', 'secretaria', 'coord_academico', 'coord_combinado'])
            && $actor->can('asistencia.correccion.aprobar');
    }

    private function mayHandle(User $actor, AsignacionDocente $assignment): bool
    {
        return $this->teacher($actor) && ((! $assignment->trashed() && $assignment->docente_id === $actor->id)
            || ($actor->can('asistencia.consultar_grupo')
                && $actor->hasAnyRole(['rector', 'coord_academico', 'coord_combinado'])));
    }

    public function ownAbsences(Request $request): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($this->student($actor), 403);
        $query = DB::table('asistencia_marcas as m')
            ->join('asistencia_clases as c', 'c.id', '=', 'm.asistencia_clase_id')
            ->join('matriculas as matricula', 'matricula.id', '=', 'm.matricula_id')
            ->join('materias as materia', 'materia.id', '=', 'c.materia_id')
            ->join('grupos as grupo', 'grupo.id', '=', 'c.grupo_id')
            ->where('matricula.estudiante_id', $actor->id)->where('m.estado', 'ausente');
        if ($request->filled('ano_lectivo_token')) {
            $year = OpaqueUrlToken::find('ano-lectivo', $request->query('ano_lectivo_token'), AnoLectivo::query());
            abort_unless($year, 404);
            $query->where('c.ano_lectivo_id', $year->id);
        }
        $page = $this->paginateAcademic($query->orderByDesc('c.fecha')->orderBy('c.hora_inicio'), $request,
            ['m.matricula_id', 'c.sesion_horario_id', 'c.asignacion_id', 'c.fecha', 'c.hora_inicio', 'c.hora_fin',
                'materia.nombre as materia', 'grupo.nombre as grupo'], 'page', 'per_page');

        return response()->json(['data' => [
            'faltas' => $page->getCollection()->map(fn ($row) => [
                'matricula_token' => OpaqueUrlToken::for('matricula', $row->matricula_id),
                'sesion_token' => OpaqueUrlToken::for('sesion-horario', $row->sesion_horario_id),
                'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $row->asignacion_id),
                'fecha' => $row->fecha, 'hora_inicio' => substr($row->hora_inicio, 0, 5),
                'hora_fin' => substr($row->hora_fin, 0, 5), 'materia' => $row->materia, 'grupo' => $row->grupo,
            ])->values(),
            'pagination' => $this->paginationMeta($page),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        $data = $request->validate([
            'scope' => ['required', Rule::in(['propias', 'docente', 'aprobacion'])],
            'estado' => ['nullable', Rule::in(['revision_docente', 'pendiente_aprobacion', 'aprobada', 'rechazada'])],
        ]);
        $query = AsistenciaSolicitud::query();
        if ($data['scope'] === 'propias') {
            abort_unless($this->student($actor), 403);
            $query->whereIn('matricula_id', Matricula::where('estudiante_id', $actor->id)->select('id'));
        } elseif ($data['scope'] === 'docente') {
            abort_unless($this->teacher($actor), 403);
            if (! ($actor->can('asistencia.consultar_grupo')
                && $actor->hasAnyRole(['rector', 'coord_academico', 'coord_combinado']))) {
                $query->whereIn('asignacion_id', AsignacionDocente::where('docente_id', $actor->id)->select('id'));
            }
        } else {
            abort_unless($this->approver($actor), 403);
        }
        if (! empty($data['estado'])) {
            $query->where('estado', $data['estado']);
        }
        $page = $this->paginateAcademic($query->orderByDesc('id'), $request);
        $rows = $page->getCollection();
        $items = DB::table('asistencia_solicitud_marcas as item')
            ->join('asistencia_marcas as mark', 'mark.id', '=', 'item.asistencia_marca_id')
            ->join('asistencia_clases as class', 'class.id', '=', 'mark.asistencia_clase_id')
            ->whereIn('item.solicitud_id', $rows->pluck('id'))->get()
            ->groupBy('solicitud_id');
        $enrollments = Matricula::with('estudiante:id,name')->whereIn('id', $rows->pluck('matricula_id'))
            ->get()->keyBy('id');
        $assignments = AsignacionDocente::withTrashed()->with(['materia:id,nombre', 'grupo.grado:id,nombre'])
            ->whereIn('id', $rows->pluck('asignacion_id'))->get()->keyBy('id');

        return response()->json(['data' => [
            'solicitudes' => $rows->map(function ($row) use ($items, $enrollments, $assignments) {
                $assignment = $assignments->get($row->asignacion_id);

                return [
                    'token' => OpaqueUrlToken::for('asistencia-solicitud', $row->id),
                    'estudiante' => StudentRosterName::display($enrollments->get($row->matricula_id)?->estudiante?->name ?? 'Estudiante no disponible'),
                    'asignatura' => $assignment?->materia?->nombre,
                    'grupo' => $assignment?->grupo?->grado?->nombre.' / ('.$assignment?->grupo?->nombre.')',
                    'origen' => $row->origen, 'estado' => $row->estado, 'motivo' => $row->motivo,
                    'respuesta_docente' => $row->respuesta_docente,
                    'respuesta_aprobador' => $row->respuesta_aprobador,
                    'created_at' => $row->created_at,
                    'marcas' => ($items->get($row->id) ?? collect())->map(fn ($item) => [
                        'fecha' => $item->fecha, 'hora_inicio' => substr($item->hora_inicio, 0, 5),
                        'hora_fin' => substr($item->hora_fin, 0, 5),
                        'estado_anterior' => $item->estado_anterior,
                    ])->values(),
                ];
            })->values(),
            'pagination' => $this->paginationMeta($page),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        $data = $request->validate([
            'matricula_token' => ['required', 'string'],
            'asignacion_token' => ['nullable', 'string'],
            'marcas' => ['required', 'array', 'min:1', 'max:20'],
            'marcas.*.sesion_token' => ['required', 'string'],
            'marcas.*.fecha' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $student = $this->student($actor);
        abort_unless($student || $this->teacher($actor), 403);
        $enrollment = OpaqueUrlToken::find('matricula', $data['matricula_token'],
            Matricula::query()->when($student, fn ($q) => $q->where('estudiante_id', $actor->id)));
        abort_unless($enrollment, 404);
        $assignment = ! empty($data['asignacion_token'])
            ? OpaqueUrlToken::find('asignacion-docente', $data['asignacion_token'], AsignacionDocente::withTrashed()) : null;
        abort_if(! $student && ! $assignment, 422, 'Selecciona una asignatura asignada.');
        if ($assignment) {
            abort_unless($this->mayHandle($actor, $assignment) || $student, 403);
        }
        $result = DB::transaction(function () use ($actor, $data, $enrollment, $assignment, $student) {
            $year = AnoLectivo::whereKey($enrollment->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            abort_unless(! $year->estaCerrado(), 422,
                'Reabre el año lectivo antes de solicitar una corrección de asistencia.');
            $sessionTokens = array_column($data['marcas'], 'sesion_token');
            $sessionIds = OpaqueUrlToken::ids('sesion-horario', $sessionTokens, SesionHorario::withTrashed());
            abort_unless(count($sessionIds) === count(array_unique($sessionTokens)), 422,
                'Hay franjas repetidas o no disponibles.');
            $seen = [];
            $marks = [];
            foreach ($data['marcas'] as $entry) {
                $key = $entry['sesion_token'].'|'.$entry['fecha'];
                abort_if(isset($seen[$key]), 422, 'No repitas una misma franja y fecha.');
                $seen[$key] = true;
                $mark = DB::table('asistencia_marcas as m')
                    ->join('asistencia_clases as c', 'c.id', '=', 'm.asistencia_clase_id')
                    ->where('c.sesion_horario_id', $sessionIds[$entry['sesion_token']] ?? -1)
                    ->where('c.fecha', $entry['fecha'])->where('m.matricula_id', $enrollment->id)
                    ->select('m.id', 'm.estado', 'c.asignacion_id', 'c.ano_lectivo_id', 'c.grupo_id')
                    ->lockForUpdate()->first();
                abort_unless($mark && in_array($mark->estado, ['ausente', 'tarde'], true), 422,
                    'Solo se pueden justificar inasistencias o tardanzas registradas.');
                abort_unless($mark->ano_lectivo_id === $enrollment->ano_lectivo_id
                    && $mark->grupo_id === $enrollment->grupo_id, 422);
                $assignment ??= AsignacionDocente::withTrashed()->findOrFail($mark->asignacion_id);
                abort_unless($mark->asignacion_id === $assignment->id, 422,
                    'Las franjas de la solicitud deben corresponder a la misma asignatura.');
                $marks[] = $mark;
            }
            abort_unless($student || $this->mayHandle($actor, $assignment), 403);
            abort_unless($assignment->ano_lectivo_id === $enrollment->ano_lectivo_id, 422);
            $duplicate = DB::table('asistencia_solicitud_marcas as item')
                ->join('asistencia_solicitudes as s', 's.id', '=', 'item.solicitud_id')
                ->whereIn('item.asistencia_marca_id', array_column($marks, 'id'))
                ->whereIn('s.estado', ['revision_docente', 'pendiente_aprobacion'])->exists();
            abort_if($duplicate, 409, 'Ya existe una solicitud pendiente para una de estas franjas.');
            $status = $student ? 'revision_docente' : 'pendiente_aprobacion';
            $row = AsistenciaSolicitud::create([
                'ano_lectivo_id' => $enrollment->ano_lectivo_id,
                'asignacion_id' => $assignment->id, 'matricula_id' => $enrollment->id,
                'solicitado_por_id' => $actor->id, 'remitido_por_id' => $student ? null : $actor->id,
                'origen' => $student ? 'estudiante' : 'docente', 'estado' => $status,
                'motivo' => trim($data['motivo']), 'remitido_at' => $student ? null : now(),
            ]);
            foreach ($marks as $mark) {
                DB::table('asistencia_solicitud_marcas')->insert([
                    'solicitud_id' => $row->id, 'asistencia_marca_id' => $mark->id,
                    'estado_anterior' => $mark->estado, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            AuditLogger::tenant($actor, 'CREATE', 'asistencia_solicitud', (string) $row->id, null,
                ['estado' => $status, 'matricula_id' => $enrollment->id,
                    'marcas' => array_column($marks, 'id'), 'motivo' => $row->motivo]);

            return ['token' => OpaqueUrlToken::for('asistencia-solicitud', $row->id), 'estado' => $status];
        });

        return response()->json(['data' => $result], 201);
    }

    public function review(Request $request, string $token): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($this->teacher($actor), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['remitir', 'rechazar'])],
            'respuesta' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('decision') === 'rechazar')],
        ]);
        $result = DB::transaction(function () use ($actor, $token, $data) {
            $row = OpaqueUrlToken::find('asistencia-solicitud', $token, AsistenciaSolicitud::query());
            abort_unless($row, 404);
            $row = AsistenciaSolicitud::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $assignment = AsignacionDocente::withTrashed()->findOrFail($row->asignacion_id);
            abort_unless($this->mayHandle($actor, $assignment), 403);
            abort_unless($row->estado === 'revision_docente', 409, 'La solicitud ya fue revisada.');
            if ($data['decision'] === 'remitir') {
                $this->assertMarksStillPending($row);
            }
            $before = $row->estado;
            $row->update([
                'estado' => $data['decision'] === 'remitir' ? 'pendiente_aprobacion' : 'rechazada',
                'remitido_por_id' => $data['decision'] === 'remitir' ? $actor->id : null,
                'remitido_at' => $data['decision'] === 'remitir' ? now() : null,
                'respuesta_docente' => $data['respuesta'] ?? null,
                'resuelto_por_id' => $data['decision'] === 'rechazar' ? $actor->id : null,
                'resuelto_at' => $data['decision'] === 'rechazar' ? now() : null,
            ]);
            AuditLogger::tenant($actor, 'UPDATE', 'asistencia_solicitud', (string) $row->id,
                ['estado' => $before], ['estado' => $row->estado, 'respuesta_docente' => $row->respuesta_docente]);

            return ['estado' => $row->estado];
        });

        return response()->json(['data' => $result]);
    }

    public function resolve(Request $request, string $token): JsonResponse
    {
        $this->plan();
        $actor = $request->user();
        abort_unless($this->approver($actor), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['aprobar', 'rechazar'])],
            'respuesta' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('decision') === 'rechazar')],
        ]);
        $result = DB::transaction(function () use ($actor, $token, $data) {
            $row = OpaqueUrlToken::find('asistencia-solicitud', $token, AsistenciaSolicitud::query());
            abort_unless($row, 404);
            $row = AsistenciaSolicitud::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->estado === 'pendiente_aprobacion', 409, 'La solicitud ya fue resuelta.');
            if ($data['decision'] === 'aprobar') {
                $year = AnoLectivo::whereKey($row->ano_lectivo_id)->lockForUpdate()->firstOrFail();
                abort_unless(! $year->estaCerrado(), 422,
                    'Reabre el año lectivo antes de corregir su asistencia.');
                $marks = $this->assertMarksStillPending($row);
                $classIds = [];
                foreach ($marks as $mark) {
                    DB::table('asistencia_marcas')->where('id', $mark->id)->update([
                        'estado' => 'justificada', 'registrado_por_id' => $actor->id, 'updated_at' => now(),
                    ]);
                    $classIds[$mark->asistencia_clase_id] = true;
                    AuditLogger::tenant($actor, 'UPDATE', 'asistencia_marca', (string) $mark->id,
                        ['estado' => $mark->estado], ['estado' => 'justificada', 'solicitud_id' => $row->id]);
                }
                DB::table('asistencia_clases')->whereIn('id', array_keys($classIds))->update([
                    'version' => DB::raw('version + 1'), 'updated_at' => now(),
                ]);
            }
            $row->update([
                'estado' => $data['decision'] === 'aprobar' ? 'aprobada' : 'rechazada',
                'respuesta_aprobador' => $data['respuesta'] ?? null,
                'resuelto_por_id' => $actor->id, 'resuelto_at' => now(),
            ]);
            AuditLogger::tenant($actor, 'UPDATE', 'asistencia_solicitud', (string) $row->id,
                ['estado' => 'pendiente_aprobacion'], ['estado' => $row->estado,
                    'respuesta_aprobador' => $row->respuesta_aprobador]);

            return ['estado' => $row->estado];
        });

        return response()->json(['data' => $result]);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function assertMarksStillPending(AsistenciaSolicitud $row): \Illuminate\Support\Collection
    {
        $marks = DB::table('asistencia_solicitud_marcas as item')
            ->join('asistencia_marcas as mark', 'mark.id', '=', 'item.asistencia_marca_id')
            ->where('item.solicitud_id', $row->id)
            ->select('mark.id', 'mark.estado', 'mark.asistencia_clase_id', 'item.estado_anterior')
            ->lockForUpdate()->get();
        abort_unless($marks->isNotEmpty() && $marks->every(fn ($mark) => $mark->estado === $mark->estado_anterior),
            409, 'Una inasistencia cambió antes de resolver esta solicitud.');

        return $marks;
    }
}
