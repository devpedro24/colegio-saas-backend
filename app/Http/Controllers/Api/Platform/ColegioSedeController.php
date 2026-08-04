<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sedes de un colegio gestionadas por el SUPERADMIN desde el panel central.
 *
 * Las sedes viven en la BD del tenant; cada operacion se ejecuta dentro de
 * `$tenant->run()`. Aplica el mismo gating de plan (max_sedes) que el lado
 * del colegio. Toda operacion sensible queda en el log de auditoria de
 * plataforma (RN-AI-003).
 */
class ColegioSedeController extends Controller
{
    /** Lista las sedes del colegio (principal primero). */
    public function index(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        // Se serializa DENTRO de $tenant->run(): al terminar, Stancl desconecta
        // la conexion del tenant y un modelo colgado no puede acceder a fechas.
        $sedes = $tenant->run(function () {
            return Sede::query()
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Sede $sede) => $this->snapshot($sede))
                ->values();
        });

        return response()->json(['data' => $sedes]);
    }

    /** Crea una sede en el colegio. */
    public function store(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        $error = null;
        $sede = null;
        $tenant->run(function () use ($data, &$sede, &$error) {
            if (SedeLimits::alLimite()) {
                $error = 'El plan del colegio permite máximo '.(string) SedeLimits::maxSedes().' sede(s).';

                return;
            }

            if (Sede::where('nombre', $data['nombre'])->exists()) {
                $error = 'Ya existe una sede con ese nombre.';

                return;
            }

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
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        AuditLogger::platform(
            $request->user(),
            'CREATE',
            'colegio.sede',
            (string) $tenant->id,
            null,
            $this->snapshot($sede),
            null,
            (string) $tenant->id,
        );

        return response()->json(['data' => $this->snapshot($sede)], 201);
    }

    /** Edita una sede del colegio. */
    public function update(Request $request, string $id, int $sedeId): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        $error = null;
        $prev = null;
        $sede = null;
        $tenant->run(function () use ($data, $sedeId, &$prev, &$sede, &$error) {
            $sede = Sede::withTrashed()->find($sedeId);

            if (! $sede || $sede->trashed()) {
                $error = 'La sede no existe.';

                return;
            }

            if (Sede::where('nombre', $data['nombre'])->where('id', '!=', $sede->id)->exists()) {
                $error = 'Ya existe una sede con ese nombre.';

                return;
            }

            $prev = $this->snapshot($sede);

            if ($data['es_principal'] ?? false) {
                Sede::query()->where('id', '!=', $sede->id)->update(['es_principal' => false]);
            }

            $sede->update([
                'nombre' => $data['nombre'],
                'direccion' => $data['direccion'] ?? $sede->direccion,
                'telefono' => $data['telefono'] ?? $sede->telefono,
                'responsable' => $data['responsable'] ?? $sede->responsable,
                'es_principal' => $data['es_principal'] ?? $sede->es_principal,
                'estado' => $data['estado'] ?? $sede->estado,
            ]);
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        AuditLogger::platform(
            $request->user(),
            'UPDATE',
            'colegio.sede',
            (string) $tenant->id,
            $prev,
            $this->snapshot($sede),
            null,
            (string) $tenant->id,
        );

        return response()->json(['data' => $this->snapshot($sede)]);
    }

    /** Elimina (soft-delete) una sede del colegio. */
    public function destroy(Request $request, string $id, int $sedeId): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $error = null;
        $prev = null;
        $tenant->run(function () use ($sedeId, &$prev, &$error) {
            $sede = Sede::withTrashed()->find($sedeId);

            if (! $sede || $sede->trashed()) {
                $error = 'La sede no existe.';

                return;
            }

            $prev = $this->snapshot($sede);
            $sede->delete();
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        AuditLogger::platform(
            $request->user(),
            'DELETE',
            'colegio.sede',
            (string) $tenant->id,
            $prev,
            null,
            null,
            (string) $tenant->id,
        );

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(?Sede $sede): array
    {
        if ($sede === null) {
            return [];
        }

        return [
            'id' => $sede->id,
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'telefono' => $sede->telefono,
            'responsable' => $sede->responsable,
            'es_principal' => $sede->es_principal,
            'estado' => $sede->estado,
        ];
    }
}