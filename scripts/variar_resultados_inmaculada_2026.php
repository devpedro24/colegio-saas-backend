<?php

declare(strict_types=1);

/** Casos de bajo desempeño en Matemáticas para probar promoción y recuperación. */

use App\Models\Academico\Calificacion;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
$result = $tenant->run(function () use ($apply): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->firstOrFail();
    $names = ['Primero', 'Segundo', 'Tercero', 'Cuarto'];
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->whereIn('d.nombre', $names)
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')->orderBy('g.id')
        ->get(['g.id', 'g.nombre', 'd.nombre as grado']);
    $plans = [];
    foreach ($names as $name) {
        $group = $groups->firstWhere('grado', $name);
        if (! $group) {
            throw new RuntimeException("Falta el grupo de {$name}.");
        }
        $enrollment = DB::table('matriculas')->where('ano_lectivo_id', $year->id)
            ->where('grupo_id', $group->id)->where('estado', 'activa')->orderByDesc('id')->first();
        $assignment = DB::table('asignaciones_docentes as x')
            ->join('materias as m', 'm.id', '=', 'x.materia_id')
            ->where('x.ano_lectivo_id', $year->id)->where('x.grupo_id', $group->id)
            ->where('m.nombre', 'Matemáticas')->whereNull('x.deleted_at')
            ->first(['x.id', 'x.docente_id']);
        if (! $enrollment || ! $assignment?->docente_id) {
            throw new RuntimeException("{$name} no tiene estudiante o docente de Matemáticas.");
        }
        $grades = Calificacion::query()->join('actividades_evaluacion as a', 'a.id', '=', 'calificaciones.actividad_id')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
            ->where('c.asignacion_id', $assignment->id)->where('calificaciones.matricula_id', $enrollment->id)
            ->select('calificaciones.*')->orderBy('calificaciones.id')->get();
        if ($grades->count() !== 32 || $grades->contains(fn ($grade) => $grade->id <= 14)) {
            throw new RuntimeException("{$name}: no son 32 notas sintéticas independientes de las 14 originales.");
        }
        $plans[] = ['grupo' => $group, 'matricula' => $enrollment, 'asignacion' => $assignment,
            'notas' => $grades];
    }
    if (! $apply) {
        return ['modo' => 'vista_previa', 'grupos' => array_map(fn ($plan) => [
            'grado' => $plan['grupo']->grado, 'grupo' => $plan['grupo']->nombre,
            'matricula' => $plan['matricula']->id, 'notas_sinteticas' => $plan['notas']->count(),
        ], $plans)];
    }
    $changed = DB::transaction(function () use ($plans): int {
        $changed = 0;
        foreach ($plans as $plan) {
            foreach ($plan['notas'] as $grade) {
                $new = number_format(2 + ((int) sprintf('%u', crc32("{$grade->actividad_id}:{$grade->matricula_id}")) % 10) / 10, 1, '.', '');
                if ((string) $grade->valor === $new) {
                    continue;
                }
                $before = $grade->toArray();
                $grade->update(['valor' => $new, 'updated_by' => $plan['asignacion']->docente_id,
                    'version' => $grade->version + 1]);
                AuditLogger::tenant(null, 'UPDATE', 'calificacion', (string) $grade->id,
                    $before, $grade->toArray(),
                    'Variación sintética local para verificar recuperación y promoción; registro realizado hoy.');
                $changed++;
            }
        }
        return $changed;
    });
    return ['modo' => 'aplicado', 'notas_sinteticas_ajustadas' => $changed,
        'notas_anteriores_conservadas' => 14];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
