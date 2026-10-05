<?php

declare(strict_types=1);

/** Verificación de solo lectura de los siete boletines de muestra. */

use App\Models\Academico\Matricula;
use App\Models\Tenant;
use App\Services\GradebookService;
use App\Support\EvaluationOpaquePresenter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
$result = $tenant->run(function (): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->firstOrFail();
    $target = ['Prejardín', 'Jardín', 'Transición', 'Primero', 'Segundo', 'Tercero', 'Cuarto'];
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->whereIn('d.nombre', $target)
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')->orderBy('g.id')
        ->get(['g.id', 'g.nombre', 'g.cupo_maximo', 'd.nombre as grado']);
    $checks = [];
    foreach ($target as $name) {
        $group = $groups->firstWhere('grado', $name);
        $enrollment = Matricula::with(['grupo.grado', 'estudiante'])
            ->where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)
            ->where('estado', 'activa')->orderBy('id')->firstOrFail();
        $internal = app(GradebookService::class)->report($enrollment);
        $public = EvaluationOpaquePresenter::report($internal);
        $subjects = collect($public['asignaturas']);
        $pending = $subjects->sum(fn ($row) => collect($row['periodos'])->where('estado', 'pendiente')->count()
            + (int) (($row['anual']['estado'] ?? '') === 'pendiente'));
        $visual = in_array($name, ['Prejardín', 'Jardín', 'Transición'], true);
        $publicFirst = $subjects->first()['periodos'][0] ?? [];
        if ($subjects->count() !== 11 || $pending > 0
            || $visual !== isset($public['escala_visual'])
            || ($visual && (! isset($publicFirst['valoracion'])
                || isset($publicFirst['display_value']) || isset($publicFirst['raw_value'])))) {
            throw new RuntimeException("Boletín incompleto o incorrecto para {$name}: ".json_encode([
                'asignaturas' => $subjects->count(), 'pendientes' => $pending, 'visual' => $visual,
                'primer_resultado' => $publicFirst], JSON_UNESCAPED_UNICODE));
        }
        $checks[] = ['grado' => $name, 'grupo' => $group->nombre, 'asignaturas' => $subjects->count(),
            'pendientes' => $pending, 'modo' => $visual ? 'caritas' : 'numerico',
            'primer_resultado' => $visual ? $publicFirst['valoracion']['nombre'] : $publicFirst['display_value']];
    }
    $selectedIds = collect($target)->map(fn ($name) => $groups->firstWhere('grado', $name)->id)->all();
    $missingGrades = DB::table('actividades_evaluacion as a')
        ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
        ->join('asignaciones_docentes as x', 'x.id', '=', 'c.asignacion_id')
        ->join('matriculas as m', function ($join) use ($year) {
            $join->on('m.grupo_id', '=', 'x.grupo_id')
                ->where('m.ano_lectivo_id', '=', $year->id)->where('m.estado', '=', 'activa');
        })
        ->leftJoin('calificaciones as n', function ($join) {
            $join->on('n.actividad_id', '=', 'a.id')->on('n.matricula_id', '=', 'm.id');
        })
        ->whereIn('x.grupo_id', $selectedIds)->where('x.ano_lectivo_id', $year->id)
        ->whereNull('x.deleted_at')->whereNull('n.id')->count();
    if ($missingGrades !== 0) {
        throw new RuntimeException("Hay {$missingGrades} celdas de planilla sin valoración.");
    }
    $promotionCases = [];
    foreach (['Primero', 'Segundo', 'Tercero', 'Cuarto'] as $name) {
        $group = $groups->firstWhere('grado', $name);
        $last = Matricula::with(['grupo.grado', 'estudiante'])->where('ano_lectivo_id', $year->id)
            ->where('grupo_id', $group->id)->where('estado', 'activa')->orderByDesc('id')->firstOrFail();
        $report = app(GradebookService::class)->report($last);
        $failed = collect($report['asignaturas'])->filter(fn ($row) => ($row['anual']['estado'] ?? null) === 'calculado'
            && ! ($row['anual']['aprobado'] ?? false))->pluck('nombre')->all();
        if (! in_array('Matemáticas', $failed, true)) {
            throw new RuntimeException("Falta el caso de recuperación/promoción en {$name}.");
        }
        $promotionCases[] = ['grado' => $name, 'materias_reprobadas' => $failed];
    }
    return ['ano' => '2026', 'boletines_verificados' => $checks,
        'celdas_sin_valoracion' => $missingGrades,
        'casos_promocion' => $promotionCases,
        'notas_totales_en_tenant' => DB::table('calificaciones')->count(),
        'periodos' => DB::table('periodos')->where('ano_lectivo_id', $year->id)
            ->orderBy('orden')->get(['nombre', 'estado'])->all()];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
