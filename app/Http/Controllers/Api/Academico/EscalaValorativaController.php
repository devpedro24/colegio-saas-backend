<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\EscalaValorativa;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escala valorativa (bloque 4) — BD del tenant.
 *
 * Se versiona por ano lectivo y puede variar por nivel (RN-CC-003). `index`
 * filtra por `ano_lectivo_id`; `store` hace upsert por (ano_lectivo_id, nivel_educativo)
 * para no duplicar la escala de un mismo nivel/ano. Permiso `academico.configurar`.
 */
class EscalaValorativaController extends Controller
{
    /** Lista las escalas, filtrable por `ano_lectivo_id`. */
    public function index(Request $request): JsonResponse
    {
        $escalas = EscalaValorativa::query()
            ->when(
                $request->filled('ano_lectivo_id'),
                fn ($q) => $q->where('ano_lectivo_id', (int) $request->query('ano_lectivo_id')),
            )
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('nivel_educativo')
            ->get();

        return response()->json(['data' => $escalas]);
    }

    /** Crea o actualiza (upsert) la escala de un ano lectivo (y nivel opcional). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $existente = EscalaValorativa::query()
            ->where('ano_lectivo_id', $data['ano_lectivo_id'])
            ->where('nivel_educativo', $data['nivel_educativo'] ?? null)
            ->first();

        $prev = $existente?->only(array_keys($data));

        $escala = EscalaValorativa::query()->updateOrCreate(
            [
                'ano_lectivo_id' => $data['ano_lectivo_id'],
                'nivel_educativo' => $data['nivel_educativo'] ?? null,
            ],
            $data,
        );

        AuditLogger::tenant(
            $request->user(),
            $existente ? 'UPDATE' : 'CREATE',
            'config.escala',
            (string) $escala->id,
            $prev,
            $escala->only(array_keys($data)),
        );

        // Gate de operabilidad: si este bloque completa la config minima, activa.
        ConfigurationGate::maybeActivate($request->user());

        return response()->json(['data' => $escala], $existente ? 200 : 201);
    }

    /** Actualiza una escala existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $escala = EscalaValorativa::query()->findOrFail($id);
        $data = $this->validated($request);

        $prev = $escala->only(array_keys($data));
        $escala->update($data);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'config.escala',
            (string) $escala->id,
            $prev,
            $escala->only(array_keys($data)),
        );

        return response()->json(['data' => $escala]);
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
            'nivel_educativo' => ['nullable', 'in:preescolar,primaria,secundaria,media'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:numerica,imagenes'],
            'valor_min' => ['nullable', 'numeric'],
            'valor_max' => ['nullable', 'numeric', 'gte:valor_min'],
            'decimales' => ['nullable', 'integer', 'min:0', 'max:5'],
        ]);
    }

    /** Elimina una escala existente por id. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $escala = EscalaValorativa::query()->findOrFail($id);
        $prev = $escala->only(['ano_lectivo_id', 'nivel_educativo', 'nombre', 'tipo', 'valor_min', 'valor_max', 'decimales']);

        $escala->delete();

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'config.escala',
            (string) $escala->id,
            $prev,
            null,
        );

        return response()->json(['data' => null]);
    }
}
