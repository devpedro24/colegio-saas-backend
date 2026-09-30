<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\MetodoAprobacion;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use App\Support\ConfigOpaqueData;
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
        $yearId = $request->boolean('opaque')
            ? ConfigOpaqueData::year($request)->id
            : ($request->filled('ano_lectivo_id') ? (int) $request->query('ano_lectivo_id') : null);
        $metodos = MetodoAprobacion::query()
            ->when($yearId !== null, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->orderByDesc('ano_lectivo_id')
            ->get();

        return response()->json(['data' => $metodos->map(fn ($metodo) => $this->present($metodo))]);
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

        return response()->json(['data' => $this->present($metodo)], $existente ? 200 : 201);
    }

    /** Actualiza un metodo existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $metodo = MetodoAprobacion::query()->findOrFail($id);
        $data = $this->validated($request);
        if ($request->boolean('opaque')) {
            abort_unless($metodo->ano_lectivo_id === $data['ano_lectivo_id'], 422);
        }

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

        return response()->json(['data' => $this->present($metodo)]);
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
            'calculo_nota' => ['required', 'in:promedio_simple,ponderado,sumatoria'],
            'nota_minima' => ['required', 'numeric', 'min:0'],
            'ambito' => ['required', 'in:materia,area,promedio_general'],
        ]);
        if ($opaque) {
            $data['ano_lectivo_id'] = ConfigOpaqueData::year($request)->id;
            unset($data['ano_lectivo_token']);
        }

        return $data;
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

    private function present(MetodoAprobacion $metodo): MetodoAprobacion|array
    {
        return request()->boolean('opaque')
            ? ConfigOpaqueData::present($metodo, 'metodo-aprobacion',
                ['calculo_nota', 'nota_minima', 'ambito'])
            : $metodo;
    }
}
