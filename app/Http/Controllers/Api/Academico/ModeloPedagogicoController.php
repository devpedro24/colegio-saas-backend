<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\ModeloPedagogico;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modelo pedagogico (bloque 6) — BD del tenant.
 *
 * Se versiona por ano lectivo y aplica por nivel. `index` filtra por
 * `ano_lectivo_id`; `store` hace upsert por (ano_lectivo_id, nivel_educativo).
 * Permiso `academico.configurar`.
 */
class ModeloPedagogicoController extends Controller
{
    /** Lista los modelos, filtrable por `ano_lectivo_id`. */
    public function index(Request $request): JsonResponse
    {
        $modelos = ModeloPedagogico::query()
            ->when(
                $request->filled('ano_lectivo_id'),
                fn ($q) => $q->where('ano_lectivo_id', (int) $request->query('ano_lectivo_id')),
            )
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('nivel_educativo')
            ->get();

        return response()->json(['data' => $modelos]);
    }

    /** Crea o actualiza (upsert) el modelo pedagogico de un ano lectivo (y nivel opcional). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $existente = ModeloPedagogico::query()
            ->where('ano_lectivo_id', $data['ano_lectivo_id'])
            ->where('nivel_educativo', $data['nivel_educativo'] ?? null)
            ->first();

        $prev = $existente?->only(array_keys($data));

        $modelo = ModeloPedagogico::query()->updateOrCreate(
            [
                'ano_lectivo_id' => $data['ano_lectivo_id'],
                'nivel_educativo' => $data['nivel_educativo'] ?? null,
            ],
            $data,
        );

        AuditLogger::tenant(
            $request->user(),
            $existente ? 'UPDATE' : 'CREATE',
            'config.modelo_pedagogico',
            (string) $modelo->id,
            $prev,
            $modelo->only(array_keys($data)),
        );

        // Gate de operabilidad: si este bloque completa la config minima, activa.
        ConfigurationGate::maybeActivate($request->user());

        return response()->json(['data' => $modelo], $existente ? 200 : 201);
    }

    /** Actualiza un modelo existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $modelo = ModeloPedagogico::query()->findOrFail($id);
        $data = $this->validated($request);

        $prev = $modelo->only(array_keys($data));
        $modelo->update($data);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'config.modelo_pedagogico',
            (string) $modelo->id,
            $prev,
            $modelo->only(array_keys($data)),
        );

        return response()->json(['data' => $modelo]);
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
            'nivel_educativo' => ['required', 'in:preescolar,primaria,secundaria,media'],
            'docente_unico' => ['required', 'boolean'],
            'salon_fijo' => ['required', 'boolean'],
            'tiene_director_grupo' => ['required', 'boolean'],
        ]);
    }

    /** Elimina un modelo existente por id. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $modelo = ModeloPedagogico::query()->findOrFail($id);
        $prev = $modelo->only(['ano_lectivo_id', 'nivel_educativo', 'docente_unico', 'salon_fijo', 'tiene_director_grupo']);

        $modelo->delete();

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'config.modelo_pedagogico',
            (string) $modelo->id,
            $prev,
            null,
        );

        return response()->json(['data' => null]);
    }
}
