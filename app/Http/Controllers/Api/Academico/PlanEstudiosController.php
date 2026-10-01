<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Area;
use App\Models\Academico\Materia;
use App\Models\Academico\Nivel;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Áreas y materias persistentes del plan de estudios. */
class PlanEstudiosController extends Controller
{
    use PaginatesRequests;

    public function areas(Request $request): JsonResponse
    {
        $areas = $this->paginateAcademic(Area::query()->withCount('materias')
            ->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            ->orderBy('nombre')->orderBy('id'), $request);

        return $this->paginatedResponse($areas, 'area');
    }

    public function storeArea(Request $request): JsonResponse
    {
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::editable($year);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('areas', 'nombre')->where('ano_lectivo_id', $year->id)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in([Area::ESTADO_ACTIVO, Area::ESTADO_INACTIVO])],
        ]);
        $area = Area::create($data + ['ano_lectivo_id' => $year->id, 'estado' => $data['estado'] ?? Area::ESTADO_ACTIVO]);
        AuditLogger::tenant($request->user(), 'CREATE', 'area', (string) $area->id, null, $area->toArray());
        TenantDataChanged::dispatch('area', 'created', $area->nombre);

        return response()->json(['data' => $this->withOpaqueToken($area, 'area')], 201);
    }

    public function updateArea(Request $request, int $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($area->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:120', Rule::unique('areas', 'nombre')->where('ano_lectivo_id', $year->id)->ignore($area->id)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'estado' => ['nullable', Rule::in([Area::ESTADO_ACTIVO, Area::ESTADO_INACTIVO])],
        ]);
        $before = $area->toArray();
        $area->update($data);
        AuditLogger::tenant($request->user(), 'UPDATE', 'area', (string) $area->id, $before, $area->fresh()->toArray());
        TenantDataChanged::dispatch('area', 'updated', $area->nombre);

        return response()->json(['data' => $this->withOpaqueToken($area->fresh(), 'area')]);
    }

    public function destroyArea(Request $request, int $id): JsonResponse
    {
        $area = Area::withCount('materias')->findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($area->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
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
        $request->validate(['nivel_general' => ['nullable', 'boolean']]);
        abort_if($request->boolean('nivel_general') && $request->filled('nivel_id'), 422,
            'Selecciona un nivel educativo o filtra las materias de Todos los niveles, no ambos.');
        $materias = $this->paginateAcademic(Materia::query()->with(['area:id,nombre', 'nivel:id,nombre'])
            ->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->integer('area_id')))
            ->when($request->boolean('nivel_general'), fn ($q) => $q->whereNull('nivel_id'))
            ->when($request->filled('nivel_id'), fn ($q) => $q->where('nivel_id', $request->integer('nivel_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            ->orderBy('nombre')->orderBy('id'), $request);

        return $this->paginatedResponse($materias, 'materia');
    }

    public function storeMateria(Request $request): JsonResponse
    {
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::editable($year);
        $data = $this->validarMateria($request, $year->id);
        $this->validarPadresMateria($data, $year);
        $materia = Materia::create($data + ['ano_lectivo_id' => $year->id, 'estado' => $data['estado'] ?? Materia::ESTADO_ACTIVO]);
        AuditLogger::tenant($request->user(), 'CREATE', 'materia', (string) $materia->id, null, $materia->toArray());
        TenantDataChanged::dispatch('materia', 'created', $materia->nombre);

        return response()->json(['data' => $this->withOpaqueToken($materia->load(['area:id,nombre', 'nivel:id,nombre']), 'materia')], 201);
    }

    public function updateMateria(Request $request, int $id): JsonResponse
    {
        $materia = Materia::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($materia->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        $data = $this->validarMateria($request, $year->id, $materia);
        $this->validarPadresMateria($data, $year);
        $before = $materia->toArray();
        $materia->update($data);
        AuditLogger::tenant($request->user(), 'UPDATE', 'materia', (string) $materia->id, $before, $materia->fresh()->toArray());
        TenantDataChanged::dispatch('materia', 'updated', $materia->nombre);

        return response()->json(['data' => $this->withOpaqueToken($materia->fresh()->load(['area:id,nombre', 'nivel:id,nombre']), 'materia')]);
    }

    public function destroyMateria(Request $request, int $id): JsonResponse
    {
        $materia = Materia::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($materia->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        $before = $materia->toArray();
        $materia->delete();
        AuditLogger::tenant($request->user(), 'DELETE', 'materia', (string) $materia->id, $before, null);
        TenantDataChanged::dispatch('materia', 'deleted', $materia->nombre);

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function validarMateria(Request $request, int $yearId, ?Materia $materia = null): array
    {
        return $request->validate([
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->whereNull('deleted_at')],
            'nivel_id' => [$materia ? 'sometimes' : 'present', 'nullable', 'integer', 'exists:niveles,id'],
            'nombre' => [$materia ? 'sometimes' : 'required', 'string', 'max:120'],
            'codigo' => ['nullable', 'string', 'max:30', Rule::unique('materias', 'codigo')->where('ano_lectivo_id', $yearId)->ignore($materia?->id)],
            'intensidad_horaria' => [$materia ? 'sometimes' : 'required', 'integer', 'min:1', 'max:40'],
            'estado' => ['nullable', Rule::in([Materia::ESTADO_ACTIVO, Materia::ESTADO_INACTIVO])],
        ]);
    }

    private function validarPadresMateria(array $data, \App\Models\Academico\AnoLectivo $year): void
    {
        if (! empty($data['area_id'])) {
            AcademicYearSelection::assertSame(Area::findOrFail($data['area_id'])->ano_lectivo_id, $year);
        }
        if (! empty($data['nivel_id'])) {
            AcademicYearSelection::assertSame(Nivel::findOrFail($data['nivel_id'])->ano_lectivo_id, $year);
        }
    }
}
