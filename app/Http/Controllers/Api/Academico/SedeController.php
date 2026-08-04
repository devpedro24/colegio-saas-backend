<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\Sede;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sedes del colegio (jerarquía Bloque B). Permiso `academico.estructura.gestionar`.
 */
class SedeController extends Controller
{
    /** Lista las sedes (principal primero). */
    public function index(): JsonResponse
    {
        $sedes = Sede::query()
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $sedes]);
    }

    /** Detalle de una sede. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Sede::findOrFail($id)]);
    }

    /** Crea una sede. */
    public function store(Request $request): JsonResponse
    {
        // Gating SaaS: el plan limita la cantidad de sedes (esencial=1, estandar=5).
        if (SedeLimits::alLimite()) {
            return response()->json([
                'message' => 'El plan del colegio permite máximo '.(string) SedeLimits::maxSedes().' sede(s).',
            ], 422);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'unique:sedes,nombre'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        // Solo una sede puede ser principal a la vez.
        if ($data['es_principal'] ?? false) {
            Sede::query()->update(['es_principal' => false]);
        }

        $sede = Sede::create([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'responsable' => $data['responsable'] ?? null,
            'es_principal' => $data['es_principal'] ?? false,
            'estado' => $data['estado'] ?? Sede::ESTADO_ACTIVA,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'sede', (string) $sede->id, null, $this->snapshot($sede));

        return response()->json(['data' => $sede], 201);
    }

    /** Edita una sede. */
    public function update(Request $request, int $id): JsonResponse
    {
        $sede = Sede::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('sedes', 'nombre')->ignore($sede->id)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        if ($data['es_principal'] ?? false) {
            Sede::query()->where('id', '!=', $sede->id)->update(['es_principal' => false]);
        }

        $prev = $this->snapshot($sede);
        $sede->update([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? $sede->direccion,
            'telefono' => $data['telefono'] ?? $sede->telefono,
            'responsable' => $data['responsable'] ?? $sede->responsable,
            'es_principal' => $data['es_principal'] ?? $sede->es_principal,
            'estado' => $data['estado'] ?? $sede->estado,
        ]);

        AuditLogger::tenant($request->user(), 'UPDATE', 'sede', (string) $sede->id, $prev, $this->snapshot($sede));

        return response()->json(['data' => $sede]);
    }

    /** Elimina (soft-delete) una sede. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $sede = Sede::findOrFail($id);
        $prev = $this->snapshot($sede);

        $sede->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'sede', (string) $sede->id, $prev, null);

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Sede $sede): array
    {
        return [
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'telefono' => $sede->telefono,
            'responsable' => $sede->responsable,
            'es_principal' => $sede->es_principal,
            'estado' => $sede->estado,
        ];
    }
}
