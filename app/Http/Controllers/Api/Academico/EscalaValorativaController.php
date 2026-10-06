<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\EscalaValorativa;
use App\Services\ConfigurationGate;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\ConfigOpaqueData;
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
        $yearId = $request->boolean('opaque')
            ? ConfigOpaqueData::year($request)->id
            : ($request->filled('ano_lectivo_id') ? (int) $request->query('ano_lectivo_id') : null);
        $escalas = EscalaValorativa::with('opciones')
            ->when($yearId !== null, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('nivel_educativo')
            ->get();

        $opcionesUsadas = $request->boolean('opaque')
            ? \Illuminate\Support\Facades\DB::table('calificaciones')
                ->whereIn('escala_opcion_id', $escalas->flatMap(fn ($escala) => $escala->opciones->pluck('id'))->all())
                ->distinct()->pluck('escala_opcion_id')->flip()
            : collect();

        return response()->json(['data' => $escalas->map(fn ($escala) => $this->present($escala,
            $escala->opciones->contains(fn ($opcion) => $opcionesUsadas->has($opcion->id))))]);
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

        if ($existente && ($existente->tipo !== $data['tipo'] || $existente->nivel_educativo !== ($data['nivel_educativo'] ?? null))
            && \Illuminate\Support\Facades\DB::table('calificaciones')->whereIn('escala_opcion_id', $existente->opciones()->pluck('id'))->exists()) {
            abort(422, 'La escala ya tiene valoraciones; no puedes cambiar su tipo ni nivel educativo.');
        }
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

        try {
            TenantDataChanged::dispatch('escala', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($escala)], $existente ? 200 : 201);
    }

    /** Actualiza una escala existente por id. */
    public function update(Request $request, int $id): JsonResponse
    {
        $escala = EscalaValorativa::query()->findOrFail($id);
        $data = $this->validated($request);
        if ($request->boolean('opaque')) {
            abort_unless($escala->ano_lectivo_id === $data['ano_lectivo_id'], 422);
        }

        $prev = $escala->only(array_keys($data));
        if (($escala->tipo !== $data['tipo'] || $escala->nivel_educativo !== ($data['nivel_educativo'] ?? null)
            || $escala->ano_lectivo_id !== $data['ano_lectivo_id'])
            && \Illuminate\Support\Facades\DB::table('calificaciones')->whereIn('escala_opcion_id', $escala->opciones()->pluck('id'))->exists()) {
            abort(422, 'La escala ya tiene valoraciones; no puedes cambiar su tipo, nivel educativo ni año lectivo.');
        }
        $escala->update($data);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'config.escala',
            (string) $escala->id,
            $prev,
            $escala->only(array_keys($data)),
        );

        try {
            TenantDataChanged::dispatch('escala', 'updated', $escala->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($escala)]);
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
            'nivel_educativo' => ['nullable', 'in:preescolar,primaria,secundaria,media'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:numerica,imagenes'],
            'valor_min' => ['nullable', 'numeric'],
            'valor_max' => ['nullable', 'numeric', 'gte:valor_min'],
            'decimales' => ['nullable', 'integer', 'in:'.SieeConfiguration::RESULT_DECIMALS],
        ]);
        if ($opaque) {
            $data['ano_lectivo_id'] = ConfigOpaqueData::year($request)->id;
            unset($data['ano_lectivo_token']);
        }

        if ($data['tipo'] === EscalaValorativa::TIPO_NUMERICA) {
            $data['decimales'] = SieeConfiguration::RESULT_DECIMALS;
        } else {
            $data['valor_min'] = null;
            $data['valor_max'] = null;
            $data['decimales'] = null;
        }

        return $data;
    }

    /** Elimina una escala existente por id. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $escala = EscalaValorativa::query()->findOrFail($id);
        $prev = $escala->only(['ano_lectivo_id', 'nivel_educativo', 'nombre', 'tipo', 'valor_min', 'valor_max', 'decimales']);

        abort_if(\Illuminate\Support\Facades\DB::table('calificaciones')->whereIn('escala_opcion_id', $escala->opciones()->pluck('id'))->exists(),
            422, 'No se puede eliminar una escala con valoraciones registradas.');
        $escala->opciones()->delete();
        $escala->delete();

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'config.escala',
            (string) $escala->id,
            $prev,
            null,
        );

        try {
            TenantDataChanged::dispatch('escala', 'deleted', $escala->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    private function present(EscalaValorativa $escala, ?bool $opcionesBloqueadas = null): EscalaValorativa|array
    {
        if (! request()->boolean('opaque')) return $escala->loadMissing('opciones');

        return [...ConfigOpaqueData::present($escala, 'escala-valorativa',
            ['nombre', 'nivel_educativo', 'tipo', 'valor_min', 'valor_max', 'decimales']),
            'opciones_bloqueadas' => $opcionesBloqueadas ?? \Illuminate\Support\Facades\DB::table('calificaciones')
                ->whereIn('escala_opcion_id', $escala->opciones->pluck('id'))->exists(),
            'opciones' => $escala->opciones->map(EscalaOpcionController::present(...))->all()];
    }
}
