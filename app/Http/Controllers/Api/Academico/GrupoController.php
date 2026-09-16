<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\Grupo;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Grupos (curso concreto: grado + año lectivo + jornada — RN-JO-002).
 * Permiso `academico.estructura.gestionar`.
 */
class GrupoController extends Controller
{
    /** Lista los grupos, filtrables por `ano_lectivo_id` y/o `grado_id`. */
    public function index(Request $request): JsonResponse
    {
        $grupos = Grupo::query()
            ->with($this->relations(true))
            ->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', (int) $request->query('ano_lectivo_id')))
            ->when($request->filled('grado_id'), fn ($q) => $q->where('grado_id', (int) $request->query('grado_id')))
            ->when($request->filled('jornada_id'), fn ($q) => $q->where('jornada_id', (int) $request->query('jornada_id')))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('grado_id')
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $grupos]);
    }

    /** Detalle de un grupo. */
    public function show(int $id): JsonResponse
    {
        $grupo = Grupo::with($this->relations())->findOrFail($id);

        return response()->json(['data' => $grupo]);
    }

    /** Crea un grupo. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grado_id' => ['required', 'integer', 'exists:grados,id'],
            'ano_lectivo_id' => ['required', 'integer', 'exists:anos_lectivos,id'],
            'jornada_id' => ['nullable', 'integer', 'exists:jornadas,id'],
            'sede_id' => $this->hasSedeScope() ? ['nullable', 'integer', 'exists:sedes,id'] : ['prohibited'],
            'nombre' => ['required', 'string', 'max:40'],
            'cupo_maximo' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'estado' => ['nullable', Rule::in([Grupo::ESTADO_ACTIVO, Grupo::ESTADO_INACTIVO])],
        ]);

        // RN-JO-002: único por (grado, año lectivo, jornada, nombre).
        $existe = Grupo::query()
            ->where('grado_id', $data['grado_id'])
            ->where('ano_lectivo_id', $data['ano_lectivo_id'])
            ->where('jornada_id', $data['jornada_id'] ?? null)
            ->where('nombre', $data['nombre'])
            ->exists();
        if ($existe) {
            abort(422, 'Ya existe un grupo con ese nombre para el mismo grado, año lectivo y jornada.');
        }

        $attributes = [
            'grado_id' => $data['grado_id'],
            'ano_lectivo_id' => $data['ano_lectivo_id'],
            'jornada_id' => $data['jornada_id'] ?? null,
            'nombre' => $data['nombre'],
            'cupo_maximo' => $data['cupo_maximo'] ?? null,
            'estado' => $data['estado'] ?? Grupo::ESTADO_ACTIVO,
        ];
        if ($this->hasSedeScope()) {
            $attributes['sede_id'] = $data['sede_id'] ?? null;
        }
        $grupo = Grupo::create($attributes);

        AuditLogger::tenant($request->user(), 'CREATE', 'grupo', (string) $grupo->id, null, $this->snapshot($grupo));

        try {
            TenantDataChanged::dispatch('grupo', 'created', $data['nombre']);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $grupo->load($this->relations())], 201);
    }

    /** Edita un grupo. */
    public function update(Request $request, int $id): JsonResponse
    {
        $grupo = Grupo::findOrFail($id);

        $data = $request->validate([
            'grado_id' => ['sometimes', 'integer', 'exists:grados,id'],
            'ano_lectivo_id' => ['sometimes', 'integer', 'exists:anos_lectivos,id'],
            'jornada_id' => ['nullable', 'integer', 'exists:jornadas,id'],
            'sede_id' => $this->hasSedeScope() ? ['nullable', 'integer', 'exists:sedes,id'] : ['prohibited'],
            'nombre' => ['sometimes', 'string', 'max:40'],
            'cupo_maximo' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'estado' => ['nullable', Rule::in([Grupo::ESTADO_ACTIVO, Grupo::ESTADO_INACTIVO])],
        ]);

        $gradoId = $data['grado_id'] ?? $grupo->grado_id;
        $anoId = $data['ano_lectivo_id'] ?? $grupo->ano_lectivo_id;
        $jornadaId = array_key_exists('jornada_id', $data) ? $data['jornada_id'] : $grupo->jornada_id;
        $nombre = $data['nombre'] ?? $grupo->nombre;

        $existe = Grupo::query()
            ->where('grado_id', $gradoId)
            ->where('ano_lectivo_id', $anoId)
            ->where('jornada_id', $jornadaId)
            ->where('nombre', $nombre)
            ->where('id', '!=', $grupo->id)
            ->exists();
        if ($existe) {
            abort(422, 'Ya existe un grupo con ese nombre para el mismo grado, año lectivo y jornada.');
        }

        $prev = $this->snapshot($grupo);
        $grupo->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'grupo', (string) $grupo->id, $prev, $this->snapshot($grupo));

        try {
            TenantDataChanged::dispatch('grupo', 'updated', $grupo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $grupo->load($this->relations())]);
    }

    /** Elimina (soft-delete) un grupo. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $grupo = Grupo::findOrFail($id);
        $prev = $this->snapshot($grupo);

        $grupo->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'grupo', (string) $grupo->id, $prev, null);

        try {
            TenantDataChanged::dispatch('grupo', 'deleted', $grupo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Grupo $grupo): array
    {
        return [
            'grado_id' => $grupo->grado_id,
            'ano_lectivo_id' => $grupo->ano_lectivo_id,
            'jornada_id' => $grupo->jornada_id,
            'sede_id' => $grupo->sede_id,
            'nombre' => $grupo->nombre,
            'cupo_maximo' => $grupo->cupo_maximo,
            'estado' => $grupo->estado,
        ];
    }

    private function hasSedeScope(): bool
    {
        return Schema::hasTable('sedes') && Schema::hasColumn('grupos', 'sede_id');
    }

    /** @return list<string> */
    private function relations(bool $withNivel = false): array
    {
        $relations = ['grado:id,nombre,nivel_id', 'anoLectivo:id,nombre', 'jornada:id,nombre'];
        if ($withNivel) {
            $relations[] = 'grado.nivel:id,nombre';
        }
        if ($this->hasSedeScope()) {
            $relations[] = 'sede:id,nombre';
        }

        return $relations;
    }
}
