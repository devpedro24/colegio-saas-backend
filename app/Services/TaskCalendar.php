<?php

namespace App\Services;

use App\Models\Academico\AulaRecurso;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/** A projection, never a second event record. Aula remains the source of truth. */
final class TaskCalendar
{
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $items = collect();
        if (app(AcademicPlanAccess::class)->aula()) {
            $query = AulaRecurso::with('seccion.aula.grupo.grado', 'seccion.aula.materia')
                ->where('tipo', 'tarea')->where('visible_estudiantes', true)
                ->whereIn('estado', ['publicado', 'programado', 'cerrado'])
                ->where(fn ($q) => $q->where('estado', '!=', 'programado')->orWhereNotNull('disponible_desde'))
                ->whereNotNull('publicado_en')->where('publicado_en', '<=', now('UTC'))
                ->where(fn ($q) => $q->whereNull('disponible_desde')->orWhere('disponible_desde', '<=', now('UTC')))
                ->whereRaw('COALESCE(fecha_limite, publicado_en) BETWEEN ? AND ?', [
                    CarbonImmutable::parse($filters['desde'], 'UTC')->subDay(),
                    CarbonImmutable::parse($filters['hasta'], 'UTC')->addDays(2),
                ])
                ->whereHas('seccion', fn ($q) => $q->where('visible_estudiantes', true))
                ->whereHas('seccion.aula', function ($q) use ($user, $filters) {
                    if (! empty($filters['grupo_id'])) $q->where('grupo_id', $filters['grupo_id']);
                    if ($user->can('aula.ver_todas')) return;
                    $q->where(function ($scope) use ($user) {
                        $scope->whereRaw('1 = 0');
                        if ($user->can('aula.ver_asignadas')) {
                            $scope->orWhereExists(fn ($a) => $a->selectRaw('1')->from('asignaciones_docentes')
                                ->whereColumn('asignaciones_docentes.ano_lectivo_id', 'aulas.ano_lectivo_id')
                                ->whereColumn('asignaciones_docentes.grupo_id', 'aulas.grupo_id')
                                ->whereColumn('asignaciones_docentes.materia_id', 'aulas.materia_id')
                                ->whereNull('asignaciones_docentes.deleted_at')->where('docente_id', $user->id));
                        }
                        if ($user->can('aula.ver_propias')) {
                            $scope->orWhereExists(fn ($m) => $m->selectRaw('1')->from('matriculas')
                                ->whereColumn('matriculas.ano_lectivo_id', 'aulas.ano_lectivo_id')
                                ->whereColumn('matriculas.grupo_id', 'aulas.grupo_id')
                                ->where('estado', 'activa')->where('estudiante_id', $user->id));
                        }
                    });
                });
            foreach ($query->get() as $task) {
                $zone = $task->zona_publicacion ?: tenant()->zonaHorariaInstitucional();
                $date = ($task->fecha_limite ?? $task->publicado_en)->copy()->timezone($zone)->toDateString();
                if ($date < $filters['desde'] || $date > $filters['hasta']) continue;
                $aula = $task->seccion->aula;
                $group = ['url_token' => OpaqueUrlToken::for('grupo', $aula->grupo_id), 'nombre' => $aula->grupo->nombre,
                    'grado' => $aula->grupo->grado ? ['nombre' => $aula->grupo->grado->nombre] : null];
                $items->push([
                    'url_token' => OpaqueUrlToken::for('aula-recurso', $task->id), 'origen' => 'aula',
                    'titulo' => $task->titulo, 'descripcion' => $aula->materia->nombre,
                    'fecha' => $date, 'hora_inicio' => null, 'hora_fin' => null, 'categoria' => 'tarea',
                    'institucional' => false, 'grupos' => [$group], 'grupo_tokens' => [$group['url_token']],
                    'materia_token' => OpaqueUrlToken::for('materia', $aula->materia_id),
                    'estado' => 'publicado', 'publicado_en' => $task->publicado_en->toIso8601String(),
                    'fecha_limite' => $task->fecha_limite?->toIso8601String(),
                    'publicacion_estimada' => (bool) $task->publicacion_estimada,
                    'created_at' => $task->publicado_en->toIso8601String(),
                ]);
            }
        }
        $items = $items->sortBy(fn ($i) => $i['fecha'].$i['url_token'])->values();
        $page = $filters['page'] ?? 1;
        return new LengthAwarePaginator($items->forPage($page, 200)->values(), $items->count(), 200, $page);
    }
}
