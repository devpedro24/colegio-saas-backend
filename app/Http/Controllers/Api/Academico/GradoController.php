<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Events\TenantDataChanged;
use App\Models\Academico\Grado;
use App\Models\Academico\Nivel;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grados académicos (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class GradoController extends Controller
{
    use PaginatesRequests;

    /** Lista los grados, filtrables por `nivel_id`. */
    public function index(Request $request): JsonResponse
    {
        $grados = Grado::query()
            ->with('nivel:id,nombre,nivel_educativo')
            ->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('nivel_id'), fn ($q) => $q->where('nivel_id', (int) $request->query('nivel_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            // Conserva el orden histórico de niveles según su primer grado.
            ->orderByRaw('(SELECT MIN(first_grade.id) FROM grados AS first_grade WHERE first_grade.nivel_id = grados.nivel_id AND first_grade.deleted_at IS NULL)')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->resolvePerPage($request), ['*'], 'page', $this->resolvePage($request));

        return $this->paginatedResponse($grados);
    }

    /** Detalle de un grado. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Grado::with('nivel:id,nombre,nivel_educativo')->findOrFail($id)]);
    }

    /** Crea un grado. */
    public function store(Request $request): JsonResponse
    {
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::editable($year);
        $data = $request->validate([
            'nivel_id' => ['required', 'integer', 'exists:niveles,id'],
            'nombre' => ['required', 'string', 'max:80'],
            'codigo' => ['nullable', 'string', 'min:2', 'max:5'],
            'estado' => ['nullable', Rule::in([Grado::ESTADO_ACTIVO, Grado::ESTADO_INACTIVO])],
        ]);

        AcademicYearSelection::assertSame(Nivel::findOrFail($data['nivel_id'])->ano_lectivo_id, $year);
        $existe = Grado::query()->where('ano_lectivo_id', $year->id)->where('nivel_id', $data['nivel_id'])->where('nombre', $data['nombre'])->exists();
        if ($existe) {
            abort(422, 'El nivel ya tiene un grado con ese nombre.');
        }

        $grado = Grado::create([
            'ano_lectivo_id' => $year->id,
            'nivel_id' => $data['nivel_id'],
            'nombre' => $data['nombre'],
            'codigo' => $data['codigo'] ?? null,
            'estado' => $data['estado'] ?? Grado::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'grado', (string) $grado->id, null, $this->snapshot($grado));

        try {
            TenantDataChanged::dispatch('grado', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $grado->load('nivel:id,nombre,nivel_educativo')], 201);
    }

    /** Edita un grado. */
    public function update(Request $request, int $id): JsonResponse
    {
        $grado = Grado::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($grado->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);

        $data = $request->validate([
            'nivel_id' => ['sometimes', 'integer', 'exists:niveles,id'],
            'nombre' => ['sometimes', 'string', 'max:80'],
            'codigo' => ['nullable', 'string', 'min:2', 'max:5'],
            'estado' => ['nullable', Rule::in([Grado::ESTADO_ACTIVO, Grado::ESTADO_INACTIVO])],
        ]);

        if (isset($data['nivel_id'])) {
            AcademicYearSelection::assertSame(Nivel::findOrFail($data['nivel_id'])->ano_lectivo_id, $year);
        }
        if (isset($data['nivel_id']) || isset($data['nombre'])) {
            $existe = Grado::query()
                ->where('ano_lectivo_id', $year->id)
                ->where('nivel_id', $data['nivel_id'] ?? $grado->nivel_id)
                ->where('nombre', $data['nombre'] ?? $grado->nombre)
                ->where('id', '!=', $grado->id)
                ->exists();
            if ($existe) {
                abort(422, 'El nivel ya tiene un grado con ese nombre.');
            }
        }

        $prev = $this->snapshot($grado);
        $grado->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'grado', (string) $grado->id, $prev, $this->snapshot($grado));

        try {
            TenantDataChanged::dispatch('grado', 'updated', $grado->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $grado->load('nivel:id,nombre,nivel_educativo')]);
    }

    /** Elimina (soft-delete) un grado. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $grado = Grado::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($grado->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        $prev = $this->snapshot($grado);

        $grado->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'grado', (string) $grado->id, $prev, null);

        try {
            TenantDataChanged::dispatch('grado', 'deleted', $grado->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Grado $grado): array
    {
        return [
            'nivel_id' => $grado->nivel_id,
            'nombre' => $grado->nombre,
            'codigo' => $grado->codigo,
            'estado' => $grado->estado,
        ];
    }
}
