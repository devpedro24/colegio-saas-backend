<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\Nivel;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Niveles educativos (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class NivelController extends Controller
{
    /** Lista los niveles por orden. */
    public function index(): JsonResponse
    {
        $niveles = Nivel::query()
            ->withCount('grados')
            ->orderBy('orden')
            ->get();

        return response()->json(['data' => $niveles]);
    }

    /** Detalle de un nivel. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Nivel::with('grados')->findOrFail($id)]);
    }

    /** Crea un nivel. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nivel_educativo' => ['required', 'string', 'max:40', 'unique:niveles,nivel_educativo'],
            'nombre' => ['required', 'string', 'max:120'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([Nivel::ESTADO_ACTIVO, Nivel::ESTADO_INACTIVO])],
        ]);

        $nivel = Nivel::create([
            'nivel_educativo' => $data['nivel_educativo'],
            'nombre' => $data['nombre'],
            'orden' => $data['orden'] ?? 0,
            'estado' => $data['estado'] ?? Nivel::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'nivel', (string) $nivel->id, null, $this->snapshot($nivel));

        try {
            TenantDataChanged::dispatch('nivel', 'created', $data['nivel_educativo']);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $nivel], 201);
    }

    /** Edita un nivel. */
    public function update(Request $request, int $id): JsonResponse
    {
        $nivel = Nivel::findOrFail($id);

        $data = $request->validate([
            'nivel_educativo' => ['sometimes', 'string', 'max:40', Rule::unique('niveles', 'nivel_educativo')->ignore($nivel->id)],
            'nombre' => ['sometimes', 'string', 'max:120'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([Nivel::ESTADO_ACTIVO, Nivel::ESTADO_INACTIVO])],
        ]);

        $prev = $this->snapshot($nivel);
        $nivel->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'nivel', (string) $nivel->id, $prev, $this->snapshot($nivel));

        try {
            TenantDataChanged::dispatch('nivel', 'updated', $nivel->nivel_educativo);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $nivel]);
    }

    /** Elimina (soft-delete) un nivel. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $nivel = Nivel::findOrFail($id);
        $prev = $this->snapshot($nivel);

        $nivel->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'nivel', (string) $nivel->id, $prev, null);

        try {
            TenantDataChanged::dispatch('nivel', 'deleted', $nivel->nivel_educativo);
        } catch (\Throwable) {
        }

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
            'orden' => $nivel->orden,
            'estado' => $nivel->estado,
        ];
    }
}
