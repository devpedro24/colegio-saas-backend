<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\Grupo;
use App\Models\User;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Dirección de grupo: independiente de la asignación de una materia. */
final class DirectorGrupoController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $group = Grupo::with('anoLectivo')->findOrFail($id);

        return response()->json(['data' => $this->present($group)]);
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'docente_token' => ['present', 'nullable', 'string'],
            'version' => ['required', 'integer', 'min:0'],
            'motivo' => ['nullable', 'string', 'min:8', 'max:1000'],
        ]);
        $teacher = $data['docente_token'] === null ? null
            : OpaqueUrlToken::find('usuario', $data['docente_token'], User::query());
        if ($data['docente_token'] !== null && (! $teacher || $teacher->status !== User::STATUS_ACTIVE
            || ! $teacher->hasRole('docente'))) {
            throw ValidationException::withMessages(['docente_token' => 'Selecciona un docente activo del colegio.']);
        }

        $group = DB::transaction(function () use ($request, $id, $data, $teacher): Grupo {
            $group = Grupo::with('anoLectivo')->lockForUpdate()->findOrFail($id);
            AcademicYearSelection::editable($group->anoLectivo);
            abort_unless($group->estaActivo(), 422, 'El grupo debe estar activo.');
            $current = DB::table('directores_grupo')->where('grupo_id', $group->id)->lockForUpdate()->first();
            abort_unless((int) ($current?->version ?? 0) === (int) $data['version'], 409,
                'La dirección del grupo cambió. Recarga antes de guardar.');
            $previousId = $current?->docente_id;
            if ($previousId === $teacher?->id) {
                return $group;
            }
            if ($current && empty($data['motivo'])) {
                throw ValidationException::withMessages(['motivo' => 'Explica por qué cambias o retiras al director.']);
            }
            $nextVersion = (int) ($current?->version ?? 0) + 1;
            DB::table('directores_grupo')->updateOrInsert(['grupo_id' => $group->id], [
                'docente_id' => $teacher?->id, 'actualizado_por' => $request->user()->id,
                'version' => $nextVersion, 'asignado_en' => $teacher ? now() : null,
                'updated_at' => now(), 'created_at' => $current?->created_at ?? now(),
            ]);
            DB::table('directores_grupo_historial')->insert([
                'grupo_id' => $group->id, 'docente_anterior_id' => $previousId,
                'docente_nuevo_id' => $teacher?->id, 'actor_id' => $request->user()->id,
                'version' => $nextVersion, 'motivo' => $data['motivo'] ?? null, 'registrado_en' => now(),
            ]);
            AuditLogger::tenant($request->user(), $current ? 'UPDATE' : 'CREATE', 'director_grupo',
                (string) $group->id, ['docente_id' => $previousId, 'version' => $current?->version ?? 0],
                ['docente_id' => $teacher?->id, 'version' => $nextVersion], $data['motivo'] ?? null);

            return $group;
        });

        return response()->json(['data' => $this->present($group)]);
    }

    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('directores_grupo as director')
            ->join('grupos as grupo', 'grupo.id', '=', 'director.grupo_id')
            ->join('grados as grado', 'grado.id', '=', 'grupo.grado_id')
            ->join('anos_lectivos as ano', 'ano.id', '=', 'grupo.ano_lectivo_id')
            ->where('director.docente_id', $request->user()->id)
            ->whereNull('grupo.deleted_at')->where('grupo.estado', Grupo::ESTADO_ACTIVO)
            ->orderByDesc('ano.fecha_inicio')->orderBy('grado.nombre')->orderBy('grupo.nombre')
            ->get(['grupo.id', 'grupo.nombre', 'grupo.ano_lectivo_id', 'grado.nombre as grado']);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'grupo_token' => OpaqueUrlToken::for('grupo', $row->id), 'grupo' => $row->nombre,
            'grado' => $row->grado, 'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $row->ano_lectivo_id),
        ])]);
    }

    private function present(Grupo $group): array
    {
        $current = DB::table('directores_grupo')->where('grupo_id', $group->id)->first();
        $teacher = $current?->docente_id ? User::find($current->docente_id) : null;
        $history = DB::table('directores_grupo_historial as h')
            ->leftJoin('users as old', 'old.id', '=', 'h.docente_anterior_id')
            ->leftJoin('users as new', 'new.id', '=', 'h.docente_nuevo_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'h.actor_id')
            ->where('h.grupo_id', $group->id)->orderByDesc('h.id')->limit(20)
            ->get(['h.version', 'h.motivo', 'h.registrado_en', 'old.name as anterior',
                'new.name as nuevo', 'actor.name as actor']);

        return ['grupo_token' => OpaqueUrlToken::for('grupo', $group->id),
            'docente_token' => $teacher ? OpaqueUrlToken::for('usuario', $teacher->id) : null,
            'docente_nombre' => $teacher?->name, 'version' => (int) ($current?->version ?? 0),
            'historial' => $history];
    }
}
