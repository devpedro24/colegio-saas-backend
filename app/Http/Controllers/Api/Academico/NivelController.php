<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Events\TenantDataChanged;
use App\Models\Academico\Nivel;
use App\Models\Academico\AnoLectivo;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Niveles educativos (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class NivelController extends Controller
{
    use PaginatesRequests;

    /** Lista los niveles en el orden en que fueron creados. */
    public function index(?Request $request = null): JsonResponse
    {
        $request ??= request();
        $niveles = $this->paginateAcademic(Nivel::query()
            ->when($request->filled('ano_lectivo_id'), fn ($query) => $query->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($query) => $query->where('estado', $request->query('estado')))
            ->withCount('grados')
            ->orderBy('created_at')
            ->orderBy('id'), $request);

        return $this->paginatedResponse($niveles, 'nivel');
    }

    /** Detalle de un nivel. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->withOpaqueToken(Nivel::with('grados')->findOrFail($id), 'nivel')]);
    }

    /** Crea un nivel. */
    public function store(Request $request): JsonResponse
    {
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::editable($year);
        $data = $request->validate([
            'nivel_educativo' => ['required', 'string', 'max:40', Rule::unique('niveles', 'nivel_educativo')->where('ano_lectivo_id', $year->id)],
            'nombre' => ['required', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in([Nivel::ESTADO_ACTIVO, Nivel::ESTADO_INACTIVO])],
        ]);

        $nivel = Nivel::create([
            'ano_lectivo_id' => $year->id,
            'nivel_educativo' => $data['nivel_educativo'],
            'nombre' => $data['nombre'],
            'estado' => $data['estado'] ?? Nivel::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'nivel', (string) $nivel->id, null, $this->snapshot($nivel));

        try {
            TenantDataChanged::dispatch('nivel', 'created', $data['nivel_educativo']);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->withOpaqueToken($nivel, 'nivel')], 201);
    }

    /** Edita un nivel. */
    public function update(Request $request, int $id): JsonResponse
    {
        $nivel = Nivel::findOrFail($id);
        $year = $nivel->ano_lectivo_id ? AnoLectivo::findOrFail($nivel->ano_lectivo_id) : AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($nivel->ano_lectivo_id, AcademicYearSelection::fromRequest($request));
        AcademicYearSelection::editable($year);

        $data = $request->validate([
            'nivel_educativo' => ['sometimes', 'string', 'max:40', Rule::unique('niveles', 'nivel_educativo')->where('ano_lectivo_id', $year->id)->ignore($nivel->id)],
            'nombre' => ['sometimes', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in([Nivel::ESTADO_ACTIVO, Nivel::ESTADO_INACTIVO])],
        ]);

        $prev = $this->snapshot($nivel);
        $nivel->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'nivel', (string) $nivel->id, $prev, $this->snapshot($nivel));

        try {
            TenantDataChanged::dispatch('nivel', 'updated', $nivel->nivel_educativo);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->withOpaqueToken($nivel, 'nivel')]);
    }

    /** Elimina (soft-delete) un nivel. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $nivel = Nivel::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($nivel->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        $prev = $this->snapshot($nivel);

        $nivel->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'nivel', (string) $nivel->id, $prev, null);

        try {
            TenantDataChanged::dispatch('nivel', 'deleted', $nivel->nivel_educativo);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Nivel $nivel): array
    {
        return [
            'nivel_educativo' => $nivel->nivel_educativo,
            'nombre' => $nivel->nombre,
            'estado' => $nivel->estado,
        ];
    }
}
