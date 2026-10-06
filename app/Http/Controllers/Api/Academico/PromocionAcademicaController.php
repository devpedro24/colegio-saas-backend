<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\PoliticaPromocion;
use App\Models\Academico\PromocionAcademica;
use App\Services\AcademicPromotionService;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Revisión exclusiva del rector: propuesta calculada y decisión explícita son operaciones distintas. */
final class PromocionAcademicaController extends Controller
{
    use PaginatesRequests;

    public function __construct(private AcademicPromotionService $promotions) {}

    public function index(Request $request, int $ano): JsonResponse
    {
        $this->authorizeReviewer($request);
        $year = AnoLectivo::findOrFail($ano);
        $group = $request->filled('grupo_token')
            ? OpaqueUrlToken::find('grupo', $request->query('grupo_token'), Grupo::where('ano_lectivo_id', $year->id))
            : null;
        abort_if($request->filled('grupo_token') && ! $group, 404);
        $policy = PoliticaPromocion::where('ano_lectivo_id', $year->id)->first();
        $page = $this->paginateAcademic(Matricula::with(['estudiante:id,name', 'grupo.grado'])
            ->where('ano_lectivo_id', $year->id)->where('estado', 'activa')
            ->when($group, fn ($q) => $q->where('grupo_id', $group->id))->orderBy('id'), $request);
        $decisions = PromocionAcademica::where('ano_lectivo_id', $year->id)
            ->whereIn('matricula_id', $page->getCollection()->modelKeys())
            ->get()->keyBy('matricula_id');
        $rows = $page->getCollection()->map(function (Matricula $enrollment) use ($year, $policy, $decisions): array {
            $proposal = $this->promotions->proposal($year, $enrollment, $policy);
            $decision = $decisions->get($enrollment->id);

            return [
                'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
                'estudiante' => $enrollment->estudiante?->name,
                'grado' => $enrollment->grupo?->grado?->nombre,
                'grado_token' => $enrollment->grupo?->grado_id
                    ? OpaqueUrlToken::for('grado', $enrollment->grupo->grado_id) : null,
                'grupo' => $enrollment->grupo?->nombre,
                'propuesta' => $this->presentProposal($proposal),
                'decision' => $decision ? [
                    'resultado' => $decision->resultado,
                    'grado_destino_token' => $decision->grado_destino_id
                        ? OpaqueUrlToken::for('grado', $decision->grado_destino_id) : null,
                    'motivo' => $decision->motivo, 'version' => $decision->version,
                    'vigente' => $proposal['estado'] === 'calculada'
                        && hash_equals($proposal['huella'], $decision->huella)
                        && $proposal['resultado'] === $decision->propuesta,
                    'aprobada_en' => $decision->approved_at?->toIso8601String(),
                ] : null,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => $this->paginationMeta($page),
            'politica' => $policy ? [
                'max_reprobadas' => $policy->max_reprobadas,
                'materias_obligatorias' => collect($policy->materias_obligatorias ?? [])
                    ->map(fn ($id) => OpaqueUrlToken::for('materia', $id))->values(),
                'promedio_minimo' => $policy->promedio_minimo, 'version' => $policy->version,
            ] : null,
            'materias' => Materia::where('ano_lectivo_id', $year->id)->orderBy('nombre')
                ->get(['id', 'nombre'])->map(fn (Materia $item) => [
                    'token' => OpaqueUrlToken::for('materia', $item->id), 'nombre' => $item->nombre,
                ]),
            'grados' => Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo')
                ->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Grado $item) => [
                    'token' => OpaqueUrlToken::for('grado', $item->id), 'nombre' => $item->nombre,
                ]),
            'grupos' => Grupo::with('grado:id,nombre')->where('ano_lectivo_id', $year->id)
                ->orderBy('grado_id')->orderBy('nombre')->get()->map(fn (Grupo $item) => [
                    'token' => OpaqueUrlToken::for('grupo', $item->id),
                    'nombre' => $item->grado->nombre.' / ('.$item->nombre.')',
                ]),
        ]);
    }

    public function policy(Request $request, int $ano): JsonResponse
    {
        $this->authorizeReviewer($request);
        $year = AnoLectivo::findOrFail($ano);
        $data = $request->validate([
            'max_reprobadas' => ['required', 'integer', 'min:0', 'max:12'],
            'materias_obligatorias' => ['present', 'array'],
            'materias_obligatorias.*' => ['required', 'string', 'distinct'],
            'promedio_minimo' => ['nullable', 'numeric', 'decimal:0,8'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $tokens = $data['materias_obligatorias'];
        $ids = OpaqueUrlToken::ids('materia', $tokens, Materia::where('ano_lectivo_id', $year->id));
        abort_unless(count($ids) === count($tokens), 422,
            'Una materia obligatoria no pertenece a este año lectivo.');
        $policy = $this->promotions->savePolicy($request->user(), $year,
            $data['max_reprobadas'], array_values($ids), $data['promedio_minimo'] ?? null,
            $data['version']);

        return response()->json(['data' => ['version' => $policy->version]]);
    }

    public function approve(Request $request, int $ano, int $matricula): JsonResponse
    {
        $this->authorizeReviewer($request);
        $year = AnoLectivo::findOrFail($ano);
        $data = $request->validate([
            'huella' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]+\z/'],
            'resultado' => ['required', Rule::in(['promovido', 'reprobado', 'egresado'])],
            'grado_destino_token' => ['nullable', 'string'],
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $grade = null;
        if (! empty($data['grado_destino_token'])) {
            $grade = OpaqueUrlToken::find('grado', $data['grado_destino_token'],
                Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
            abort_unless($grade !== null, 422, 'Selecciona un grado destino del año lectivo.');
        }
        $decision = $this->promotions->approve($request->user(), $year,
            Matricula::where('ano_lectivo_id', $year->id)->findOrFail($matricula),
            $data['huella'], $data['resultado'], $grade, $data['motivo'], $data['version']);

        return response()->json(['data' => ['resultado' => $decision->resultado,
            'version' => $decision->version, 'aprobada_en' => $decision->approved_at?->toIso8601String()]]);
    }

    private function presentProposal(array $proposal): array
    {
        if ($proposal['estado'] !== 'calculada') {
            return $proposal;
        }

        return collect($proposal)->except(['reprobadas', 'obligatorias_reprobadas', 'insumos'])
            ->merge(['numero_reprobadas' => count($proposal['reprobadas']),
                'numero_obligatorias_reprobadas' => count($proposal['obligatorias_reprobadas'])])->all();
    }

    private function authorizeReviewer(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor && $actor->can('academico.anos.transicionar')
            && ($actor->hasRole('rector') || $actor->esSuperadminPlataforma()), 403);
    }
}
