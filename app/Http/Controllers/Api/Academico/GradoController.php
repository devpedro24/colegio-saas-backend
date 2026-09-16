<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\Grado;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grados académicos (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class GradoController extends Controller
{
    /** Lista los grados, filtrables por `nivel_id`. */
    public function index(Request $request): JsonResponse
    {
        $grados = Grado::query()
            ->with('nivel:id,nombre,nivel_educativo')
            ->when($request->filled('nivel_id'), fn ($q) => $q->where('nivel_id', (int) $request->query('nivel_id')))
            ->orderBy('nivel_id')
            ->orderBy('orden')
            ->get();

        return response()->json(['data' => $grados]);
    }

    /** Detalle de un grado. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Grado::with('nivel:id,nombre,nivel_educativo')->findOrFail($id)]);
    }

    /** Crea un grado. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nivel_id' => ['required', 'integer', 'exists:niveles,id'],
            'nombre' => ['required', 'string', 'max:80'],
            'codigo' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([Grado::ESTADO_ACTIVO, Grado::ESTADO_INACTIVO])],
        ]);

        $existe = Grado::query()->where('nivel_id', $data['nivel_id'])->where('nombre', $data['nombre'])->exists();
        if ($existe) {
            abort(422, 'El nivel ya tiene un grado con ese nombre.');
        }

        $grado = Grado::create([
            'nivel_id' => $data['nivel_id'],
            'nombre' => $data['nombre'],
            'codigo' => $data['codigo'] ?? null,
            'orden' => $data['orden'] ?? 0,
            'estado' => $data['estado'] ?? Grado::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'grado', (string) $grado->id, null, $this->snapshot($grado));

        try {
            TenantDataChanged::dispatch('grado', 'created', $data['nombre']);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $grado->load('nivel:id,nombre,nivel_educativo')], 201);
    }

    /** Edita un grado. */
    public function update(Request $request, int $id): JsonResponse
    {
        $grado = Grado::findOrFail($id);

        $data = $request->validate([
            'nivel_id' => ['sometimes', 'integer', 'exists:niveles,id'],
            'nombre' => ['sometimes', 'string', 'max:80'],
            'codigo' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([Grado::ESTADO_ACTIVO, Grado::ESTADO_INACTIVO])],
        ]);

        if (isset($data['nivel_id'], $data['nombre'])) {
            $existe = Grado::query()
                ->where('nivel_id', $data['nivel_id'])
                ->where('nombre', $data['nombre'])
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
        } catch (\Throwable) {
        }

        return response()->json(['data' => $grado->load('nivel:id,nombre,nivel_educativo')]);
    }

    /** Elimina (soft-delete) un grado. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $grado = Grado::findOrFail($id);
        $prev = $this->snapshot($grado);

        $grado->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'grado', (string) $grado->id, $prev, null);

        try {
            TenantDataChanged::dispatch('grado', 'deleted', $grado->nombre);
        } catch (\Throwable) {
        }

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
            'orden' => $grado->orden,
            'estado' => $grado->estado,
        ];
    }
}
