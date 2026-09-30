<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\ModeloPedagogico;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use App\Support\ConfigOpaqueData;
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
        $yearId = $request->boolean('opaque')
            ? ConfigOpaqueData::year($request)->id
            : ($request->filled('ano_lectivo_id') ? (int) $request->query('ano_lectivo_id') : null);
        $modelos = ModeloPedagogico::query()
            ->when($yearId !== null, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('nivel_educativo')
            ->get();

        return response()->json(['data' => $modelos->map(fn ($modelo) => $this->present($modelo))]);
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

        try {
            TenantDataChanged::dispatch('modelo', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($modelo)], $existente ? 200 : 201);
    }

    /** Actualiza un modelo existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $modelo = ModeloPedagogico::query()->findOrFail($id);
        $data = $this->validated($request);
        if ($request->boolean('opaque')) {
            abort_unless($modelo->ano_lectivo_id === $data['ano_lectivo_id'], 422);
        }

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

        try {
            TenantDataChanged::dispatch('modelo', 'updated', $modelo->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($modelo)]);
    }

    /**
     * Reglas de validacion compartidas por store/update.
     *
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $opaque = $request->boolean('opaque');
        $data = $request->validate([
            'ano_lectivo_id' => $opaque ? ['prohibited'] : ['required', 'integer', 'exists:anos_lectivos,id'],
            'ano_lectivo_token' => $opaque ? ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'] : ['prohibited'],
            'nivel_educativo' => ['required', 'in:preescolar,primaria,secundaria,media'],
            'docente_unico' => ['required', 'boolean'],
            'salon_fijo' => ['required', 'boolean'],
            'tiene_director_grupo' => ['required', 'boolean'],
        ]);
        if ($opaque) {
            $data['ano_lectivo_id'] = ConfigOpaqueData::year($request)->id;
            unset($data['ano_lectivo_token']);
        }

        return $data;
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

        try {
            TenantDataChanged::dispatch('modelo', 'deleted', $modelo->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    private function present(ModeloPedagogico $modelo): ModeloPedagogico|array
    {
        return request()->boolean('opaque')
            ? ConfigOpaqueData::present($modelo, 'modelo-pedagogico',
                ['nivel_educativo', 'docente_unico', 'salon_fijo', 'tiene_director_grupo'])
            : $modelo;
    }
}
