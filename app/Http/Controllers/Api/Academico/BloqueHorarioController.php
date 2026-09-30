<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Events\TenantDataChanged;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\Jornada;
use App\Models\Academico\SesionHorario;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bloques horarios de cada jornada (Bloque B). Permiso `academico.estructura.gestionar`.
 */
class BloqueHorarioController extends Controller
{
    use PaginatesRequests;

    /** Lista los bloques, filtrables por `jornada_id`. */
    public function index(Request $request): JsonResponse
    {
        $bloques = $this->paginateAcademic(BloqueHorario::query()
            ->with('jornada.sede:id,nombre')
            ->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('jornada_id'), fn ($q) => $q->where('jornada_id', (int) $request->query('jornada_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            ->orderBy('jornada_id')
            ->orderBy('hora_inicio')
            ->orderBy('hora_fin')
            ->orderBy('id'), $request);

        return $this->paginatedResponse($bloques, 'bloque-horario');
    }

    /** Detalle de un bloque. */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->withOpaqueToken(BloqueHorario::with('jornada.sede:id,nombre')->findOrFail($id), 'bloque-horario')]);
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
            'estado' => ['nullable', Rule::in([BloqueHorario::ESTADO_ACTIVO, BloqueHorario::ESTADO_INACTIVO])],
        ]);

        $jornadaId = (int) $data['jornada_id'];
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::editable($year);
        AcademicYearSelection::assertSame(Jornada::findOrFail($jornadaId)->ano_lectivo_id, $year);
        $this->validarDentroDeJornada($jornadaId, $data['hora_inicio'], $data['hora_fin']);
        $this->validarSolapamiento($jornadaId, $data['hora_inicio'], $data['hora_fin'], null);

        $bloque = BloqueHorario::create([
            'ano_lectivo_id' => $year->id,
            'jornada_id' => $jornadaId,
            'nombre' => $data['nombre'],
            'hora_inicio' => $data['hora_inicio'],
            'hora_fin' => $data['hora_fin'],
            'es_descanso' => $data['es_descanso'] ?? false,
            'estado' => $data['estado'] ?? BloqueHorario::ESTADO_ACTIVO,
        ]);

        AuditLogger::tenant($request->user(), 'CREATE', 'bloque_horario', (string) $bloque->id, null, $this->snapshot($bloque));

        try {
            TenantDataChanged::dispatch('bloque_horario', 'created', $data['nombre']);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->withOpaqueToken($bloque->load('jornada.sede:id,nombre'), 'bloque-horario')], 201);
    }

    /** Edita un bloque. */
    public function update(Request $request, int $id): JsonResponse
    {
        $bloque = BloqueHorario::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($bloque->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);

        $data = $request->validate([
            'jornada_id' => ['sometimes', 'integer', 'exists:jornadas,id'],
            'nombre' => ['sometimes', 'string', 'max:80'],
            'hora_inicio' => ['sometimes', 'date_format:H:i'],
            'hora_fin' => ['sometimes', 'date_format:H:i', 'after:hora_inicio'],
            'es_descanso' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([BloqueHorario::ESTADO_ACTIVO, BloqueHorario::ESTADO_INACTIVO])],
        ]);

        $jornadaId = (int) ($data['jornada_id'] ?? $bloque->jornada_id);
        AcademicYearSelection::assertSame(Jornada::findOrFail($jornadaId)->ano_lectivo_id, $year);
        $inicio = $data['hora_inicio'] ?? $bloque->hora_inicio;
        $fin = $data['hora_fin'] ?? $bloque->hora_fin;
        if (SesionHorario::where('bloque_horario_id', $bloque->id)->exists()
            && ($jornadaId !== (int) $bloque->jornada_id
                || substr($inicio, 0, 5) !== substr($bloque->hora_inicio, 0, 5)
                || substr($fin, 0, 5) !== substr($bloque->hora_fin, 0, 5)
                || (isset($data['es_descanso']) && (bool) $data['es_descanso'] !== $bloque->es_descanso)
                || (isset($data['estado']) && $data['estado'] !== $bloque->estado))) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'Este bloque ya tiene clases programadas. Cambia primero esas clases a otro bloque o a horas propias.',
            ]);
        }
        $this->validarDentroDeJornada($jornadaId, $inicio, $fin);
        $this->validarSolapamiento($jornadaId, $inicio, $fin, $bloque->id);

        $prev = $this->snapshot($bloque);
        $bloque->update($data);

        AuditLogger::tenant($request->user(), 'UPDATE', 'bloque_horario', (string) $bloque->id, $prev, $this->snapshot($bloque));

        try {
            TenantDataChanged::dispatch('bloque_horario', 'updated', $bloque->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->withOpaqueToken($bloque->load('jornada.sede:id,nombre'), 'bloque-horario')]);
    }

    /** Elimina (soft-delete) un bloque. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $bloque = BloqueHorario::findOrFail($id);
        $year = AcademicYearSelection::fromRequest($request);
        AcademicYearSelection::assertSame($bloque->ano_lectivo_id, $year);
        AcademicYearSelection::editable($year);
        abort_if(
            SesionHorario::where('bloque_horario_id', $bloque->id)->exists(),
            422,
            'Este bloque ya tiene clases programadas. Cambia primero esas clases a otro bloque o a horas propias.',
        );
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
            ->where('hora_inicio', '<', $fin)
            ->where('hora_fin', '>', $inicio)
            ->first();

        if ($solapa) {
            throw ValidationException::withMessages([
                'hora_inicio' => sprintf(
                    'Este horario se cruza con «%s» (%s–%s) de la misma jornada.',
                    $solapa->nombre,
                    substr($solapa->hora_inicio, 0, 5),
                    substr($solapa->hora_fin, 0, 5),
                ),
            ]);
        }
    }

    /** Un bloque no puede quedar por fuera del rango horario de su jornada. */
    private function validarDentroDeJornada(int $jornadaId, string $inicio, string $fin): void
    {
        $jornada = Jornada::findOrFail($jornadaId);
        if (($jornada->hora_inicio !== null && substr($inicio, 0, 5) < substr($jornada->hora_inicio, 0, 5))
            || ($jornada->hora_fin !== null && substr($fin, 0, 5) > substr($jornada->hora_fin, 0, 5))) {
            throw ValidationException::withMessages([
                'hora_inicio' => 'El bloque debe estar dentro del horario definido para su jornada.',
            ]);
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
            'estado' => $bloque->estado,
        ];
    }
}
