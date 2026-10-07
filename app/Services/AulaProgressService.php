<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Aula;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Support\OpaqueUrlToken as Token;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AulaProgressService
{
    /** @param Collection<int, Aula> $aulas
     * @return array<string, array{completados:int, total:int, porcentaje:int}>
     */
    public function forAulas(Collection $aulas, int $enrollmentId, AulaAccess $access): array
    {
        if ($aulas->isEmpty()) {
            return [];
        }
        $sections = AulaSeccion::with('recursos')->whereIn('aula_id', $aulas->pluck('id'))
            ->where('visible_estudiantes', true)->get();
        $resources = $sections->flatMap(function ($section) use ($access) {
            return $section->recursos->filter(function ($resource) use ($section, $access) {
                $resource->setRelation('seccion', $section);

                return $access->listed($resource);
            });
        })->values();
        $states = $this->forResources($resources, $enrollmentId);
        $result = [];
        foreach ($aulas as $aula) {
            $own = $sections->where('aula_id', $aula->id)
                ->flatMap(fn ($section) => $resources->where('seccion_id', $section->id));
            $total = $own->count();
            $complete = $own->filter(fn ($resource) => ($states[$resource->id] ?? null) === 'completado')->count();
            $result[Token::for('aula', $aula->id)] = [
                'completados' => $complete, 'total' => $total,
                'porcentaje' => $total ? (int) round($complete * 100 / $total) : 0,
            ];
        }

        return $result;
    }

    /** @param Collection<int, AulaRecurso> $resources
     * @return array<int, string>
     */
    public function forResources(Collection $resources, int $enrollmentId): array
    {
        $ids = $resources->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $opened = DB::table('aula_vistas_recursos')->where('matricula_id', $enrollmentId)
            ->whereIn('recurso_id', $ids)->pluck('recurso_id')->flip();
        $submissions = DB::table('aula_entregas')->where('matricula_id', $enrollmentId)
            ->whereIn('recurso_id', $ids)->get(['recurso_id', 'estado'])->keyBy('recurso_id');
        $attempts = DB::table('aula_intentos')->where('matricula_id', $enrollmentId)
            ->whereIn('recurso_id', $ids)->get(['recurso_id', 'estado'])->groupBy('recurso_id');

        $states = [];
        foreach ($resources as $resource) {
            $mode = $resource->configuracion['completar_al'] ?? match ($resource->tipo) {
                'tarea' => 'entregar', 'cuestionario' => 'finalizar', default => 'abrir',
            };
            $submission = $submissions->get($resource->id);
            if ($submission?->estado === 'borrador') $submission = null;
            $quizAttempts = $attempts->get($resource->id, collect());
            $states[$resource->id] = match ($resource->tipo) {
                'tarea' => $mode === 'abrir'
                    ? ($opened->has($resource->id) || $submission ? 'completado' : 'sin_iniciar')
                    : ($mode === 'revisar'
                        ? ($submission && in_array($submission->estado, ['revisada', 'calificada'], true)
                            ? 'completado' : ($submission ? 'pendiente' : 'sin_iniciar'))
                        : ($submission ? 'completado' : 'sin_iniciar')),
                'cuestionario' => ($quizAttempts->contains('estado', 'finalizado')
                    || ($mode === 'finalizar' && $quizAttempts->contains('estado', 'pendiente_revision'))) ? 'completado'
                    : ($quizAttempts->contains(fn ($attempt) => in_array($attempt->estado,
                        ['pendiente_revision', 'bloqueado', 'tiempo_agotado'], true))
                        ? 'pendiente' : 'sin_iniciar'),
                default => $opened->has($resource->id) ? 'completado' : 'sin_iniciar',
            };
        }

        return $states;
    }
}
