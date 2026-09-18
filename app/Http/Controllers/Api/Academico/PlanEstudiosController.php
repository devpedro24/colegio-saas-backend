<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\Area;
use App\Models\Academico\Materia;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Áreas y materias persistentes del plan de estudios. */
class PlanEstudiosController extends Controller
{
    public function areas(): JsonResponse
    {
        return response()->json(['data' => Area::query()->withCount('materias')->orderBy('nombre')->get()]);
    }

    public function storeArea(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'unique:areas,nombre'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in([Area::ESTADO_ACTIVO, Area::ESTADO_INACTIVO])],
        ]);
        $area = Area::create($data + ['estado' => $data['estado'] ?? Area::ESTADO_ACTIVO]);
        AuditLogger::tenant($request->user(), 'CREATE', 'area', (string) $area->id, null, $area->toArray());
        TenantDataChanged::dispatch('area', 'created', $area->nombre);
        return response()->json(['data' => $area], 201);
    }

    public function updateArea(Request $request, int $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:120', Rule::unique('areas', 'nombre')->ignore($area->id)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in([Area::ESTADO_ACTIVO, Area::ESTADO_INACTIVO])],
        ]);
        $before = $area->toArray();
        $area->update($data);
        AuditLogger::tenant($request->user(), 'UPDATE', 'area', (string) $area->id, $before, $area->fresh()->toArray());
        TenantDataChanged::dispatch('area', 'updated', $area->nombre);
        return response()->json(['data' => $area->fresh()]);
    }

    public function destroyArea(Request $request, int $id): JsonResponse
    {
        $area = Area::withCount('materias')->findOrFail($id);
        if ($area->materias_count > 0) {
            return response()->json(['message' => 'No puedes eliminar un área que aún tiene materias.'], 422);
        }
        $before = $area->toArray();
        $area->delete();
        AuditLogger::tenant($request->user(), 'DELETE', 'area', (string) $area->id, $before, null);
        TenantDataChanged::dispatch('area', 'deleted', $area->nombre);
        return response()->json(['data' => null]);
    }

    public function materias(Request $request): JsonResponse
    {
        $materias = Materia::query()->with(['area:id,nombre', 'nivel:id,nombre'])
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->when($request->filled('nivel_id'), fn ($q) => $q->where('nivel_id', $request->integer('nivel_id')))
            ->orderBy('nombre')->get();
        return response()->json(['data' => $materias]);
    }

    public function storeMateria(Request $request): JsonResponse
    {
        $data = $this->validarMateria($request);
        $materia = Materia::create($data + ['estado' => $data['estado'] ?? Materia::ESTADO_ACTIVO]);
        AuditLogger::tenant($request->user(), 'CREATE', 'materia', (string) $materia->id, null, $materia->toArray());
        TenantDataChanged::dispatch('materia', 'created', $materia->nombre);
        return response()->json(['data' => $materia->load(['area:id,nombre', 'nivel:id,nombre'])], 201);
    }

    public function updateMateria(Request $request, int $id): JsonResponse
    {
        $materia = Materia::findOrFail($id);
        $data = $this->validarMateria($request, $materia);
        $before = $materia->toArray();
        $materia->update($data);
        AuditLogger::tenant($request->user(), 'UPDATE', 'materia', (string) $materia->id, $before, $materia->fresh()->toArray());
        TenantDataChanged::dispatch('materia', 'updated', $materia->nombre);
        return response()->json(['data' => $materia->fresh()->load(['area:id,nombre', 'nivel:id,nombre'])]);
    }

    public function destroyMateria(Request $request, int $id): JsonResponse
    {
        $materia = Materia::findOrFail($id);
        $before = $materia->toArray();
        $materia->delete();
        AuditLogger::tenant($request->user(), 'DELETE', 'materia', (string) $materia->id, $before, null);
        TenantDataChanged::dispatch('materia', 'deleted', $materia->nombre);
        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function validarMateria(Request $request, ?Materia $materia = null): array
    {
        return $request->validate([
            'area_id' => [$materia ? 'sometimes' : 'required', 'integer', 'exists:areas,id'],
            'nivel_id' => ['nullable', 'integer', 'exists:niveles,id'],
            'nombre' => [$materia ? 'sometimes' : 'required', 'string', 'max:120'],
            'codigo' => ['nullable', 'string', 'max:30', Rule::unique('materias', 'codigo')->ignore($materia?->id)],
            'intensidad_horaria' => [$materia ? 'sometimes' : 'required', 'integer', 'min:1', 'max:40'],
            'estado' => ['nullable', Rule::in([Materia::ESTADO_ACTIVO, Materia::ESTADO_INACTIVO])],
        ]);
    }
}
