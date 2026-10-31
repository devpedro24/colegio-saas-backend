<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Grupo;
use App\Models\Academico\IndicadorVinculo;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Services\GroupSubjectScope;
use App\Services\IndicatorHtml;
use App\Services\IndicatorPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One rich-text description per group, subject and period. */
final class IndicatorSheetController extends Controller
{
    public function index(Request $request, IndicatorHtml $html): JsonResponse
    {
        $data = $request->validate(['grupo_token' => ['required', 'string'], 'periodo_token' => ['required', 'string']]);
        [$group, $period] = $this->context($data);
        abort_if(IndicatorPolicy::forYear($group->ano_lectivo_id)['alcance'] === 'grado', 409,
            'Este año usa indicadores por grado.');
        $manager = $request->user()->can('indicadores.base.gestionar');
        abort_unless($manager || $request->user()->can('indicadores.grupo.ver'), 403);
        $assigned = $manager ? null : AsignacionDocente::where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('grupo_id', $group->id)->where('docente_id', $request->user()->id)
            ->pluck('materia_id')->all();
        $subjects = GroupSubjectScope::apply(Materia::query(), $group)
            ->when(! $manager, fn ($query) => $query->whereIn('id', $assigned ?? []))
            ->orderBy('nombre')->get(['id', 'nombre']);
        $ids = $subjects->pluck('id')->all();
        $contents = DB::table('indicadores_contenidos_grupo')->where('grupo_id', $group->id)
            ->where('periodo_id', $period->id)->whereIn('materia_id', $ids)->get()->keyBy('materia_id');
        $legacy = $this->legacy($group, $period, $ids, $html);
        $policy = IndicatorPolicy::forYear($group->ano_lectivo_id);
        $canEdit = $period->estado === 'abierto' && ! AnoLectivo::findOrFail($group->ano_lectivo_id)->estaCerrado()
            && ($manager || ($request->user()->can('indicadores.grupo.editar') && $policy['docente_puede_editar']));

        return response()->json(['data' => [
            'grupo_token' => $data['grupo_token'], 'periodo_token' => $data['periodo_token'],
            'materias' => $subjects->map(function (Materia $subject) use ($contents, $legacy, $html, $canEdit): array {
                $content = $contents->get($subject->id);
                $text = $content ? $html->sanitize($content->texto_html) : ($legacy[$subject->id] ?? '');

                return ['materia_token' => OpaqueUrlToken::for('materia', $subject->id),
                    'materia' => $subject->nombre, 'texto_html' => $text,
                    'version' => (int) ($content->version ?? 0), 'puede_editar' => $canEdit];
            })->values(),
        ]]);
    }

    public function save(Request $request, string $subjectToken, IndicatorHtml $html): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'periodo_token' => ['required', 'string'],
            'texto_html' => ['required', 'string', 'max:12000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        [$group, $period, $subject, $manager] = $this->editableContext($request, $data, $subjectToken);
        $clean = $html->sanitize($data['texto_html']);
        abort_if($html->plain($clean) === '', 422, 'Escribe el indicador antes de guardar.');
        $nextVersion = DB::transaction(function () use ($request, $group, $period, $subject, $data, $clean, $manager): int {
            $year = AnoLectivo::whereKey($group->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            abort_if($year->estaCerrado() || $period->estado !== 'abierto', 422,
                'No se editan indicadores de un período o año cerrado.');
            $policy = IndicatorPolicy::forYear($year->id);
            abort_unless($policy['alcance'] === 'grupo', 409, 'Este año no usa indicadores por grupo.');
            abort_unless($manager || $policy['docente_puede_editar'], 403);
            $query = DB::table('indicadores_contenidos_grupo')->where('grupo_id', $group->id)
                ->where('materia_id', $subject->id)->where('periodo_id', $period->id);
            $current = (clone $query)->lockForUpdate()->first();
            abort_unless((int) ($current->version ?? 0) === $data['version'], 409,
                'El indicador cambió. Recarga antes de guardar.');
            $version = $data['version'] + 1;
            $values = ['texto_html' => $clean, 'version' => $version,
                'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($current) {
                $query->update($values);
            } else {
                DB::table('indicadores_contenidos_grupo')->insert([
                    'ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                    'materia_id' => $subject->id, 'periodo_id' => $period->id,
                    ...$values, 'created_at' => now(),
                ]);
            }
            AuditLogger::tenant($request->user(), $current ? 'UPDATE' : 'CREATE',
                'indicador_contenido_grupo', $group->id.':'.$subject->id.':'.$period->id,
                $current ? ['version' => $current->version, 'texto_html' => $current->texto_html] : null,
                ['version' => $version, 'texto_html' => $clean]);

            return $version;
        });

        return response()->json(['data' => ['materia_token' => $subjectToken,
            'texto_html' => $clean, 'version' => $nextVersion]]);
    }

    public function delete(Request $request, string $subjectToken): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'periodo_token' => ['required', 'string'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        [$group, $period, $subject, $manager] = $this->editableContext($request, $data, $subjectToken);
        $nextVersion = DB::transaction(function () use ($request, $group, $period, $subject, $data, $manager): int {
            $year = AnoLectivo::whereKey($group->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            abort_if($year->estaCerrado() || $period->estado !== 'abierto', 422,
                'No se eliminan indicadores de un período o año cerrado.');
            $policy = IndicatorPolicy::forYear($year->id);
            abort_unless($policy['alcance'] === 'grupo', 409, 'Este año no usa indicadores por grupo.');
            abort_unless($manager || $policy['docente_puede_editar'], 403);
            $query = DB::table('indicadores_contenidos_grupo')->where('grupo_id', $group->id)
                ->where('materia_id', $subject->id)->where('periodo_id', $period->id);
            $current = (clone $query)->lockForUpdate()->first();
            abort_unless((int) ($current->version ?? 0) === (int) $data['version'], 409,
                'El indicador cambió. Recarga antes de eliminar.');
            $version = (int) $data['version'] + 1;
            // A blank versioned row masks historical links, which would reappear after a physical delete.
            $values = ['texto_html' => '', 'version' => $version,
                'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($current) {
                $query->update($values);
            } else {
                DB::table('indicadores_contenidos_grupo')->insert([
                    'ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                    'materia_id' => $subject->id, 'periodo_id' => $period->id,
                    ...$values, 'created_at' => now(),
                ]);
            }
            AuditLogger::tenant($request->user(), 'DELETE',
                'indicador_contenido_grupo', $group->id.':'.$subject->id.':'.$period->id,
                $current ? ['version' => $current->version, 'texto_html' => $current->texto_html] : null,
                ['version' => $version, 'texto_html' => '']);

            return $version;
        });

        return response()->json(['data' => ['materia_token' => $subjectToken,
            'texto_html' => '', 'version' => $nextVersion]]);
    }

    private function editableContext(Request $request, array $data, string $subjectToken): array
    {
        [$group, $period] = $this->context($data);
        $subject = OpaqueUrlToken::find('materia', $subjectToken, Materia::query()) ?? abort(404);
        abort_unless(GroupSubjectScope::apply(Materia::whereKey($subject->id), $group)->exists(), 422,
            'La asignatura no pertenece al currículo de ese grupo.');
        $manager = $request->user()->can('indicadores.base.gestionar');
        abort_unless($manager || ($request->user()->can('indicadores.grupo.editar')
            && AsignacionDocente::where('ano_lectivo_id', $group->ano_lectivo_id)
                ->where('grupo_id', $group->id)->where('materia_id', $subject->id)
                ->where('docente_id', $request->user()->id)->exists()), 403);

        return [$group, $period, $subject, $manager];
    }

    private function context(array $data): array
    {
        $group = OpaqueUrlToken::find('grupo', $data['grupo_token'], Grupo::query()) ?? abort(404);
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'],
            Periodo::where('ano_lectivo_id', $group->ano_lectivo_id)) ?? abort(422, 'Selecciona un período del grupo.');

        return [$group, $period];
    }

    private function legacy(Grupo $group, Periodo $period, array $subjectIds, IndicatorHtml $html): array
    {
        $links = IndicadorVinculo::where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('grupo_id', $group->id)->whereIn('materia_id', $subjectIds)->where('estado', 'activo')
            ->where(fn ($query) => $query->whereNull('periodos_orden')
                ->orWhereJsonContains('periodos_orden', $period->orden))->orderBy('id')->get();
        $revisions = DB::table('indicadores_revisiones')->whereIn('id', $links->pluck('revision_id'))
            ->get()->keyBy('id');
        $variants = DB::table('indicadores_variantes')->whereIn('vinculo_id', $links->pluck('id'))
            ->where('grupo_id', $group->id)->whereIn('periodo_orden', [0, $period->orden])
            ->get()->groupBy('vinculo_id');
        $result = [];
        foreach ($links as $link) {
            $text = $variants->get($link->id)?->firstWhere('periodo_orden', $period->orden)?->texto
                ?? $variants->get($link->id)?->firstWhere('periodo_orden', 0)?->texto
                ?? $revisions->get($link->revision_id)?->texto;
            if ($text !== null) {
                $result[$link->materia_id][] = '<p>'.$html->sanitize(
                    htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
            }
        }

        return array_map(fn (array $rows) => implode('', $rows), $result);
    }
}
