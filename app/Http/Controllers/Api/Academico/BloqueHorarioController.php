<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\BloqueHorario;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bloques horarios de cada jornada (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class BloqueHorarioController extends Controller
{
    /** Lista los bloques, filtrables por `jornada_id`. */
    public function index(Request $request): JsonResponse
    {
        $bloques = BloqueHorario::query()
            ->with('jornada:id,nombre,sede_id')
            ->when($request->filled('jornada_id'), fn ($q) => $q->where('jornada_id', (int) $request->query('jornada_id')))
            ->orderBy('jornada_id')
            ->orderBy('orden')
            ->get();

        return response()->json(['data' => $bloques]);
    }

    /** Detalle de un bloque. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => BloqueHorario::with('jornada:id,nombre,sede_id')->findOrFail($id)]);
    }

    /** Crea un bloque. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jornada_id' => ['required', 'integer', 'exists:jornadas,id'],
            'nombre' => ['required', 'string', 'max:80'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'es_descanso' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([BloqueHorario::ESTADO_ACTIVO, BloqueHorario::ESTADO_INACTIVO])],
        ]);

        // Sin solapamiento con otros bloques activos de la misma jornada.
        $this->validarSolapamiento($data['jornada_id'], $data['hora_inicio'], $data['hora_fin'], null);

        $bloque = BloqueHorario::create([
            'jornada_id' => $data['jornada_id'],
            'nombre' => $data['nombre'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'es_descanso' => $data['es_descanso'] ?? false,
            'orden' => $data['orden'] ?? 0,
            'estado' => $data['estado'] ?? BloqueHorario::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'bloque_horario', (string) $bloque->id, null, $this->snapshot($bloque));

        try {
            TenantDataChanged::dispatch('bloque_horario', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $bloque->load('jornada:id,nombre,sede_id')], 201);
    }

    /** Edita un bloque. */
    public function update(Request $request, int $id): JsonResponse
    {
        $bloque = BloqueHorario::findOrFail($id);

        $data = $request->validate([
            'jornada_id' => ['sometimes', 'integer', 'exists:jornadas,id'],
            'nombre' => ['sometimes', 'string', 'max:80'],
            'hora_inicio' => ['sometimes', 'date_format:H:i'],
            'hora_fin' => ['sometimes', 'date_format:H:i', 'after:hora_inicio'],
            'es_descanso' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', Rule::in([BloqueHorario::ESTADO_ACTIVO, BloqueHorario::ESTADO_INACTIVO])],
        ]);

        $jornadaId = $data['jornada_id'] ?? $bloque->jornada_id;
        $inicio = $data['hora_inicio'] ?? $bloque->hora_inicio;
        $fin = $data['hora_fin'] ?? $bloque->hora_fin;
        $this->validarSolapamiento($jornadaId, $inicio, $fin, $bloque->id);

        $prev = $this->snapshot($bloque);
        $bloque->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'bloque_horario', (string) $bloque->id, $prev, $this->snapshot($bloque));

        try {
            TenantDataChanged::dispatch('bloque_horario', 'updated', $bloque->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $bloque->load('jornada:id,nombre,sede_id')]);
    }

    /** Elimina (soft-delete) un bloque. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $bloque = BloqueHorario::findOrFail($id);
        $prev = $this->snapshot($bloque);

        $bloque->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'bloque_horario', (string) $bloque->id, $prev, null);

        try {
            TenantDataChanged::dispatch('bloque_horario', 'deleted', $bloque->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /** Evita bloques de la misma jornada que se solapen en el tiempo. */
    private function validarSolapamiento(int $jornadaId, string $inicio, string $fin, ?int $exceptoId): void
    {
        $solapa = BloqueHorario::query()
            ->where('jornada_id', $jornadaId)
            ->when($exceptoId !== null, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->where(function ($q) use ($inicio, $fin) {
                $q->where(function ($q2) use ($inicio, $fin) {
                    $q2->where('hora_inicio', '<', $fin)->where('hora_fin', '>', $inicio);
                });
            })
            ->exists();

        if ($solapa) {
            abort(422, 'El bloque se solapa con otro bloque de la misma jornada.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BloqueHorario $bloque): array
    {
        return [
            'jornada_id' => $bloque->jornada_id,
            'nombre' => $bloque->nombre,
            'hora_inicio' => $bloque->hora_inicio,
            'hora_fin' => $bloque->hora_fin,
            'es_descanso' => $bloque->es_descanso,
            'orden' => $bloque->orden,
            'estado' => $bloque->estado,
        ];
    }
}
