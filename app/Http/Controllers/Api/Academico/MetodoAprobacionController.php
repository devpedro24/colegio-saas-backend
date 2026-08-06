<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\MetodoAprobacion;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Metodo de aprobacion (bloque 5) — BD del tenant.
 *
 * Se versiona por ano lectivo (uno por ano). `index` filtra por `ano_lectivo_id`;
 * `store` hace upsert por `ano_lectivo_id`. Permiso `academico.configurar`.
 */
class MetodoAprobacionController extends Controller
{
    /** Lista los metodos, filtrable por `ano_lectivo_id`. */
    public function index(Request $request): JsonResponse
    {
        $metodos = MetodoAprobacion::query()
            ->when(
                $request->filled('ano_lectivo_id'),
                fn ($q) => $q->where('ano_lectivo_id', (int) $request->query('ano_lectivo_id')),
            )
            ->orderByDesc('ano_lectivo_id')
            ->get();

        return response()->json(['data' => $metodos]);
    }

    /** Crea o actualiza (upsert) el metodo de aprobacion de un ano lectivo. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $existente = MetodoAprobacion::query()
            ->where('ano_lectivo_id', $data['ano_lectivo_id'])
            ->first();

        $prev = $existente?->only(array_keys($data));

        $metodo = MetodoAprobacion::query()->updateOrCreate(
            ['ano_lectivo_id' => $data['ano_lectivo_id']],
            $data,
        );

        AuditLogger::tenant(
            $request->user(),
            $existente ? 'UPDATE' : 'CREATE',
            'config.metodo_aprobacion',
            (string) $metodo->id,
            $prev,
            $metodo->only(array_keys($data)),
        );

        // Gate de operabilidad: si este bloque completa la config minima, activa.
        ConfigurationGate::maybeActivate($request->user());

        try {
            TenantDataChanged::dispatch('metodo', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $metodo], $existente ? 200 : 201);
    }

    /** Actualiza un metodo existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $metodo = MetodoAprobacion::query()->findOrFail($id);
        $data = $this->validated($request);

        $prev = $metodo->only(array_keys($data));
        $metodo->update($data);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'config.metodo_aprobacion',
            (string) $metodo->id,
            $prev,
            $metodo->only(array_keys($data)),
        );

        try {
            TenantDataChanged::dispatch('metodo', 'updated', $metodo->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $metodo]);
    }

    /**
     * Reglas de validacion compartidas por store/update.
     *
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'ano_lectivo_id' => ['required', 'integer', 'exists:anos_lectivos,id'],
            'calculo_nota' => ['required', 'in:promedio_simple,ponderado,sumatoria'],
            'nota_minima' => ['required', 'numeric', 'min:0'],
            'ambito' => ['required', 'in:materia,area,promedio_general'],
        ]);
    }

    /** Elimina un metodo existente por id. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $metodo = MetodoAprobacion::query()->findOrFail($id);
        $prev = $metodo->only(['ano_lectivo_id', 'calculo_nota', 'nota_minima', 'ambito']);

        $metodo->delete();

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'config.metodo_aprobacion',
            (string) $metodo->id,
            $prev,
            null,
        );

        try {
            TenantDataChanged::dispatch('metodo', 'deleted', $metodo->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }
}
