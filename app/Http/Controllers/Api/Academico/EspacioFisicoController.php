<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\EspacioFisico;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Espacios físicos (salones, laboratorios, ... — Bloque B).
 * Permiso `academico.estructura.gestionar`.
 */
class EspacioFisicoController extends Controller
{
    /** Lista los espacios, filtrables por `sede_id`. */
    public function index(Request $request): JsonResponse
    {
        $espacios = EspacioFisico::query()
            ->with('sede:id,nombre')
            ->when($request->filled('sede_id'), fn ($q) => $q->where('sede_id', (int) $request->query('sede_id')))
            ->orderBy('sede_id')
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $espacios]);
    }

    /** Detalle de un espacio. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => EspacioFisico::with('sede:id,nombre')->findOrFail($id)]);
    }

    /** Crea un espacio físico. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->reglas(null));

        $existe = EspacioFisico::query()
            ->where('sede_id', $data['sede_id'] ?? null)
            ->where('nombre', $data['nombre'])
            ->exists();
        if ($existe) {
            abort(422, 'Ya existe un espacio físico con ese nombre en la sede.');
        }

        $espacio = EspacioFisico::create([
            'sede_id' => $data['sede_id'] ?? null,
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'capacidad' => $data['capacidad'] ?? null,
            'ubicacion' => $data['ubicacion'] ?? null,
            'estado' => $data['estado'] ?? EspacioFisico::ESTADO_DISPONIBLE,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'espacio_fisico', (string) $espacio->id, null, $this->snapshot($espacio));

        try {
            TenantDataChanged::dispatch('espacio_fisico', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $espacio->load('sede:id,nombre')], 201);
    }

    /** Edita un espacio físico. */
    public function update(Request $request, int $id): JsonResponse
    {
        $espacio = EspacioFisico::findOrFail($id);

        $data = $request->validate($this->reglas($espacio->id));

        $sedeId = array_key_exists('sede_id', $data) ? $data['sede_id'] : $espacio->sede_id;
        $nombre = $data['nombre'] ?? $espacio->nombre;
        $existe = EspacioFisico::query()
            ->where('sede_id', $sedeId)
            ->where('nombre', $nombre)
            ->where('id', '!=', $espacio->id)
            ->exists();
        if ($existe) {
            abort(422, 'Ya existe un espacio físico con ese nombre en la sede.');
        }

        $prev = $this->snapshot($espacio);
        $espacio->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'espacio_fisico', (string) $espacio->id, $prev, $this->snapshot($espacio));

        try {
            TenantDataChanged::dispatch('espacio_fisico', 'updated', $espacio->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $espacio->load('sede:id,nombre')]);
    }

    /** Elimina (soft-delete) un espacio físico. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $espacio = EspacioFisico::findOrFail($id);
        $prev = $this->snapshot($espacio);

        $espacio->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'espacio_fisico', (string) $espacio->id, $prev, null);

        try {
            TenantDataChanged::dispatch('espacio_fisico', 'deleted', $espacio->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglas(?int $ignoreId): array
    {
        return [
            'sede_id' => ['nullable', 'integer', 'exists:sedes,id'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(EspacioFisico::TIPOS)],
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'ubicacion' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(EspacioFisico::ESTADOS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EspacioFisico $espacio): array
    {
        return [
            'sede_id' => $espacio->sede_id,
            'nombre' => $espacio->nombre,
            'tipo' => $espacio->tipo,
            'capacidad' => $espacio->capacidad,
            'ubicacion' => $espacio->ubicacion,
            'estado' => $espacio->estado,
        ];
    }
}
