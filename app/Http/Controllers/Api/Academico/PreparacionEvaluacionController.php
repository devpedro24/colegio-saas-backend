<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Grado;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Services\EvaluationPreparationService;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PreparacionEvaluacionController extends Controller
{
    public function show(Request $request, int $id, EvaluationPreparationService $service): JsonResponse
    {
        $year = AnoLectivo::findOrFail($id);
        $data = $request->validate([
            'grado_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'materia_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'periodo_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
        ]);
        [$curriculum, $period] = $this->scope($year, $data);

        return response()->json(['data' => $service->read($year, $curriculum, $period)]);
    }

    public function save(Request $request, int $id, EvaluationPreparationService $service): JsonResponse
    {
        $year = AnoLectivo::findOrFail($id);
        $data = $request->validate([
            'grado_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'materia_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'periodo_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'version' => ['required', 'integer', 'min:0'],
            'componentes' => ['required', 'array', 'max:20'],
            'componentes.*.nombre' => ['required', 'string', 'max:120'],
            'componentes.*.modo' => ['required', Rule::in(['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE'])],
            'componentes.*.peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'componentes.*.actividades' => ['required', 'array', 'max:30'],
            'componentes.*.actividades.*.nombre' => ['required', 'string', 'max:160'],
            'componentes.*.actividades.*.fecha' => ['required', 'date_format:Y-m-d'],
            'componentes.*.actividades.*.peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ]);
        [$curriculum, $period] = $this->scope($year, $data);
        $result = $service->save($year, $curriculum, $period,
            $data['componentes'], (int) $data['version'], $request->user());
        try {
            TenantDataChanged::dispatch('evaluacion', 'updated', 'Preparación de evaluación');
        } catch (\Throwable) {
        }

        return response()->json(['data' => $result]);
    }

    public function apply(Request $request, int $id, EvaluationPreparationService $service): JsonResponse
    {
        $year = AnoLectivo::findOrFail($id);
        $data = $request->validate([
            'asignacion_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'periodo_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
        ]);
        $assignment = OpaqueUrlToken::find('asignacion-docente', $data['asignacion_token'],
            AsignacionDocente::where('ano_lectivo_id', $year->id));
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'],
            Periodo::where('ano_lectivo_id', $year->id));
        abort_unless($assignment && $period, 404);
        $result = $service->apply($year, $assignment, $period, $request->user());
        if ($result['creados'] > 0) {
            try {
                TenantDataChanged::dispatch('evaluacion', 'updated', 'Planilla preparada');
            } catch (\Throwable) {
            }
        }

        return response()->json(['data' => $result]);
    }

    /** @param array<string, mixed> $data
     *  @return array{object, Periodo}
     */
    private function scope(AnoLectivo $year, array $data): array
    {
        $grade = OpaqueUrlToken::find('grado', $data['grado_token'],
            Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
        $subject = OpaqueUrlToken::find('materia', $data['materia_token'],
            Materia::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'],
            Periodo::where('ano_lectivo_id', $year->id));
        if (! $grade || ! $subject || ! $period) {
            throw ValidationException::withMessages(['seleccion' => 'Selecciona grado, materia y período del mismo año lectivo.']);
        }
        $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)
            ->where('grado_id', $grade->id)->where('materia_id', $subject->id)->first();
        abort_unless($curriculum, 422, 'Primero agrega la materia al currículo del grado.');

        return [$curriculum, $period];
    }
}
