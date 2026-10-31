<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\IndicadorBase;
use App\Models\Academico\IndicadorVinculo;
use App\Services\IndicatorPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class IndicatorPolicyController extends Controller
{
    public function show(Request $request, string $yearToken): JsonResponse
    {
        abort_unless($request->user()->can('indicadores.configurar')
            || $request->user()->can('indicadores.base.gestionar')
            || $request->user()->can('indicadores.grupo.ver'), 403);
        $year = $this->year($yearToken);

        return response()->json(['data' => IndicatorPolicy::forYear($year->id)]);
    }

    public function update(Request $request, string $yearToken): JsonResponse
    {
        abort_unless($request->user()->can('indicadores.configurar'), 403);
        $year = $this->year($yearToken);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'alcance' => ['required', Rule::in(['grado', 'grupo'])],
            'docente_puede_editar' => ['required', 'boolean'],
            'confirmar_copia' => ['sometimes', 'boolean'],
        ]);
        $teacherCanEdit = $data['alcance'] === 'grupo' && $data['docente_puede_editar'];
        DB::transaction(function () use ($request, $year, $data, $teacherCanEdit): void {
            $lockedYear = AnoLectivo::whereKey($year->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedYear->estaCerrado(), 422, 'No se cambia el alcance de un año cerrado.');
            $current = IndicatorPolicy::forYear($year->id);
            abort_unless($current['version'] === $data['version'], 409, 'La configuración cambió. Recarga.');
            if ($data['alcance'] === 'grado' && $current['alcance'] !== 'grado') {
                if ($current['indicadores_grupo'] > 0 || $current['indicadores_grado'] > 0) {
                    abort_unless($data['confirmar_copia'] ?? false, 409,
                        'Confirma que los indicadores anteriores quedarán conservados y el grado comenzará vacío.');
                }
                IndicadorVinculo::where('ano_lectivo_id', $year->id)->whereNull('grupo_id')
                    ->where('estado', 'activo')->update(['estado' => 'archivado']);
            }
            if ($data['alcance'] === 'grupo' && $current['indicadores_grado'] > 0
                && $current['alcance'] !== 'grupo') {
                abort_unless($data['confirmar_copia'] ?? false, 409,
                    'Confirma la copia de los indicadores del grado a cada grupo; los originales se conservarán.');
                $this->copyGradeIndicators($request, $year->id);
            }
            if ($current['configurado']) {
                DB::table('politicas_indicadores')->where('ano_lectivo_id', $year->id)->update([
                    'alcance' => $data['alcance'], 'docente_puede_editar' => $teacherCanEdit,
                    'version' => $current['version'] + 1, 'updated_by' => $request->user()->id, 'updated_at' => now(),
                ]);
            } else {
                DB::table('politicas_indicadores')->insert([
                    'ano_lectivo_id' => $year->id, 'alcance' => $data['alcance'],
                    'docente_puede_editar' => $teacherCanEdit, 'version' => 1,
                    'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            AuditLogger::tenant($request->user(), 'UPDATE', 'politica_indicadores', (string) $year->id,
                ['alcance' => $current['alcance'], 'docente_puede_editar' => $current['docente_puede_editar']],
                ['alcance' => $data['alcance'], 'docente_puede_editar' => $teacherCanEdit]);
        });

        return response()->json(['data' => IndicatorPolicy::forYear($year->id)]);
    }

    private function copyGradeIndicators(Request $request, int $yearId): void
    {
        $groups = DB::table('grupos')->where('ano_lectivo_id', $yearId)->get(['id', 'grado_id'])->groupBy('grado_id');
        $links = IndicadorVinculo::where('ano_lectivo_id', $yearId)->whereNull('grupo_id')
            ->where('estado', 'activo')->get();
        foreach ($links as $link) {
            $base = IndicadorBase::findOrFail($link->indicador_id);
            $revision = DB::table('indicadores_revisiones')->where('id', $link->revision_id)->first();
            foreach ($groups->get($link->grado_id, collect()) as $group) {
                $copy = IndicadorBase::create([
                    'texto' => $revision->texto, 'orden' => $base->orden, 'estado' => $base->estado,
                    'version' => 1, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                ]);
                $revisionId = DB::table('indicadores_revisiones')->insertGetId([
                    'indicador_id' => $copy->id, 'version' => 1, 'texto' => $revision->texto,
                    'actor_id' => $request->user()->id, 'created_at' => now(),
                ]);
                $newLink = IndicadorVinculo::create([
                    'indicador_id' => $copy->id, 'revision_id' => $revisionId,
                    'ano_lectivo_id' => $yearId, 'grado_id' => $link->grado_id,
                    'grupo_id' => $group->id, 'materia_id' => $link->materia_id,
                    'periodos_orden' => $link->periodos_orden, 'estado' => $link->estado,
                    'version' => 1, 'created_by' => $request->user()->id,
                ]);
                foreach (DB::table('indicadores_variantes')->where('vinculo_id', $link->id)
                    ->where('grupo_id', $group->id)->get() as $variant) {
                    DB::table('indicadores_variantes')->insert([
                        'vinculo_id' => $newLink->id, 'grupo_id' => $group->id,
                        'periodo_orden' => $variant->periodo_orden, 'texto' => $variant->texto,
                        'version' => $variant->version, 'updated_by' => $request->user()->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                AuditLogger::tenant($request->user(), 'CREATE', 'indicador_vinculo', (string) $newLink->id,
                    null, ['copiado_de_vinculo' => $link->id, 'grupo_id' => $group->id]);
            }
        }
        IndicadorVinculo::where('ano_lectivo_id', $yearId)->whereNull('grupo_id')
            ->where('estado', 'activo')->update(['estado' => 'archivado']);
    }

    private function year(string $token): AnoLectivo
    {
        return OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query()) ?? abort(404);
    }
}
