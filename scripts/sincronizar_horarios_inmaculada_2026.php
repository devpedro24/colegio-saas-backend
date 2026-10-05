<?php

declare(strict_types=1);

/** Sincroniza solo sesiones sin docente en los siete grupos auditados. */

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
    $names = ['Prejardín', 'Jardín', 'Transición', 'Primero', 'Segundo', 'Tercero', 'Cuarto'];
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->whereIn('d.nombre', $names)
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')->orderBy('g.id')
        ->get(['g.id', 'd.nombre as grado']);
    $groupIds = collect($names)->map(fn ($name) => $groups->firstWhere('grado', $name)?->id)->all();
    if (in_array(null, $groupIds, true)) {
        throw new RuntimeException('No están los siete grupos esperados.');
    }
    $sessions = DB::table('sesiones_horario as s')
        ->join('grupos as g', 'g.id', '=', 's.grupo_id')
        ->leftJoin('asignaciones_docentes as a', 'a.id', '=', 's.asignacion_id')
        ->where('g.ano_lectivo_id', $year->id)->whereNull('s.deleted_at')
        ->get(['s.id', 's.grupo_id', 's.dia', 's.hora_inicio', 's.hora_fin',
            's.docente_id', 'a.docente_id as docente_asignacion']);
    $candidates = $sessions->filter(fn ($s) => in_array($s->grupo_id, $groupIds)
        && $s->docente_id === null && $s->docente_asignacion !== null);
    foreach ($candidates as $candidate) {
        foreach ($sessions as $other) {
            if ($candidate->id === $other->id || $candidate->grupo_id === $other->grupo_id
                || $candidate->dia !== $other->dia) {
                continue;
            }
            $otherTeacher = $other->docente_id ?? $other->docente_asignacion;
            if ($candidate->docente_asignacion == $otherTeacher
                && $candidate->hora_inicio < $other->hora_fin
                && $other->hora_inicio < $candidate->hora_fin) {
                throw new RuntimeException("Cruce docente entre clases {$candidate->id} y {$other->id}; no se sincronizó.");
            }
        }
    }
    if (! $apply) {
        return ['modo' => 'vista_previa', 'sesiones_sin_docente_a_sincronizar' => $candidates->count()];
    }
    DB::transaction(function () use ($candidates) {
        foreach ($candidates as $candidate) {
            DB::table('sesiones_horario')->where('id', $candidate->id)->whereNull('docente_id')
                ->update(['docente_id' => $candidate->docente_asignacion, 'updated_at' => now()]);
            AuditLogger::tenant(null, 'UPDATE', 'sesion_horario', (string) $candidate->id,
                ['docente_id' => null], ['docente_id' => $candidate->docente_asignacion],
                'Sincronización con la asignación docente del mismo grupo y materia; sin cruces.');
        }
    });
    return ['modo' => 'aplicado', 'sesiones_sincronizadas' => $candidates->count()];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
