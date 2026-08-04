<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\Jornada;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jornadas académicas (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class JornadaController extends Controller
{
    /** Lista las jornadas, filtrables por `sede_id`. */
    public function index(Request $request): JsonResponse
    {
        $jornadas = Jornada::query()
            ->with('sede:id,nombre')
            ->when($request->filled('sede_id'), fn ($q) => $q->where('sede_id', (int) $request->query('sede_id')))
            ->orderBy('sede_id')
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $jornadas]);
    }

    /** Detalle de una jornada (incluye bloques horarios). */
    public function show(int $id): JsonResponse
    {
        $jornada = Jornada::with('sede:id,nombre', 'bloques')->findOrFail($id);

        return response()->json(['data' => $jornada]);
    }

    /** Crea una jornada. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'nombre' => ['required', 'string', 'max:80'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'after_or_equal:hora_inicio'],
            'estado' => ['nullable', Rule::in([Jornada::ESTADO_ACTIVA, Jornada::ESTADO_INACTIVA])],
        ]);

        $existe = Jornada::query()->where('sede_id', $data['sede_id'])->where('nombre', $data['nombre'])->exists();
        if ($existe) {
            abort(422, 'La sede ya tiene una jornada con ese nombre.');
        }

        $jornada = Jornada::create([
            'sede_id' => $data['sede_id'],
            'nombre' => $data['nombre'],
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fin' => $data['hora_fin'] ?? null,
            'estado' => $data['estado'] ?? Jornada::ESTADO_ACTIVA,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'jornada', (string) $jornada->id, null, $this->snapshot($jornada));

        // Gate de operabilidad: la primera jornada completa el bloque 'jornadas';
        // si con esto se completa la config minima, el colegio pasa a activo.
        ConfigurationGate::maybeActivate($request->user());

        return response()->json(['data' => $jornada->load('sede:id,nombre')], 201);
    }

    /** Edita una jornada. */
    public function update(Request $request, int $id): JsonResponse
    {
        $jornada = Jornada::findOrFail($id);

        $data = $request->validate([
            'sede_id' => ['sometimes', 'integer', 'exists:sedes,id'],
            'nombre' => ['sometimes', 'string', 'max:80'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'after_or_equal:hora_inicio'],
            'estado' => ['nullable', Rule::in([Jornada::ESTADO_ACTIVA, Jornada::ESTADO_INACTIVA])],
        ]);

        if (isset($data['sede_id'], $data['nombre'])) {
            $existe = Jornada::query()
                ->where('sede_id', $data['sede_id'])
                ->where('nombre', $data['nombre'])
                ->where('id', '!=', $jornada->id)
                ->exists();
            if ($existe) {
                abort(422, 'La sede ya tiene una jornada con ese nombre.');
            }
        }

        $prev = $this->snapshot($jornada);
        $jornada->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'jornada', (string) $jornada->id, $prev, $this->snapshot($jornada));

        return response()->json(['data' => $jornada->load('sede:id,nombre')]);
    }

    /** Elimina (soft-delete) una jornada. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $jornada = Jornada::findOrFail($id);
        $prev = $this->snapshot($jornada);

        $jornada->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'jornada', (string) $jornada->id, $prev, null);

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Jornada $jornada): array
    {
        return [
            'sede_id' => $jornada->sede_id,
            'nombre' => $jornada->nombre,
            'hora_inicio' => $jornada->hora_inicio,
            'hora_fin' => $jornada->hora_fin,
            'estado' => $jornada->estado,
        ];
    }
}
