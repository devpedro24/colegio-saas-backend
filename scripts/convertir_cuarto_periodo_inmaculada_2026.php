<?php

declare(strict_types=1);

/**
 * Reconciliación explícita del cuarto período de Inmaculada 2026.
 * Conserva actividades y calificaciones con sus IDs; agrupa las actividades
 * previas en el primer preinforme. Requiere respaldo y confirmación del colegio.
 * Sin --apply solamente valida y muestra el plan.
 */

use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
$apply = in_array('--apply', $argv, true);
$result = $tenant->run(function () use ($apply): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->where('estado', 'en_curso')->whereNull('deleted_at')->first();
    $period = $year ? Periodo::where('ano_lectivo_id', $year->id)->where('orden', 4)->first() : null;
    if (! $period || $period->estado !== 'abierto' || $period->configuracion_notas !== null
        || Preinforme::where('periodo_id', $period->id)->exists()) {
        throw new RuntimeException('El cuarto período no está en el estado original esperado.');
    }
    $components = ComponenteEvaluacion::where('periodo_id', $period->id)->orderBy('id')->get();
    $activities = DB::table('actividades_evaluacion')->whereIn('componente_id', $components->modelKeys())->get();
    $gradeCount = DB::table('calificaciones')->whereIn('actividad_id', $activities->pluck('id'))->count();
    $start = Carbon::parse($period->fecha_inicio);
    $days = $start->diffInDays(Carbon::parse($period->fecha_fin)) + 1;
    $firstEnd = $start->copy()->addDays((int) floor($days / 4) - 1)->toDateString();
    if ($activities->contains(fn ($activity) => $activity->fecha < $period->fecha_inicio->toDateString()
        || $activity->fecha > $firstEnd)) {
        throw new RuntimeException('Hay actividades fuera del primer preinforme; requiere distribución manual.');
    }
    $preview = ['componentes' => $components->count(), 'actividades_conservadas' => $activities->count(),
        'notas_conservadas' => $gradeCount, 'primer_preinforme_fin' => $firstEnd,
        'preinformes_nuevos' => 4, 'pesos' => [20, 25, 25, 30]];
    if (! $apply) {
        return ['modo' => 'vista_previa', ...$preview];
    }

    DB::transaction(function () use ($period, $components, $activities, $gradeCount, $start, $days) {
        $period = Periodo::lockForUpdate()->findOrFail($period->id);
        $preinformes = [];
        foreach ([20, 25, 25, 30] as $index => $weight) {
            $from = $start->copy()->addDays((int) floor($days * $index / 4));
            $to = $index === 3 ? Carbon::parse($period->fecha_fin)
                : $start->copy()->addDays((int) floor($days * ($index + 1) / 4) - 1);
            $pre = Preinforme::create(['periodo_id' => $period->id,
                'nombre' => ['Primer preinforme', 'Segundo preinforme', 'Tercer preinforme', 'Cuarto preinforme'][$index],
                'orden' => $index + 1, 'peso' => $weight,
                'fecha_inicio' => $from->toDateString(), 'fecha_fin' => $to->toDateString()]);
            $preinformes[] = $pre;
            AuditLogger::tenant(null, 'CREATE', 'preinforme', (string) $pre->id, null, $pre->toArray(),
                'Carga sintética local y conversión de planillas 2026.');
        }
        foreach ($components->groupBy('asignacion_id') as $assignmentComponents) {
            $primary = $assignmentComponents->first();
            foreach ($assignmentComponents->skip(1) as $secondary) {
                DB::table('actividades_evaluacion')->where('componente_id', $secondary->id)
                    ->update(['componente_id' => $primary->id, 'updated_at' => now()]);
                AuditLogger::tenant(null, 'UPDATE', 'componente_evaluacion', (string) $secondary->id,
                    $secondary->toArray(), ['actividades_trasladadas_a' => $primary->id],
                    'Consolidación en el primer preinforme; las actividades y notas mantienen sus IDs.');
                $secondary->delete();
            }
            $before = $primary->toArray();
            $primary->update(['nombre' => $preinformes[0]->nombre, 'peso' => $preinformes[0]->peso,
                'preinforme_id' => $preinformes[0]->id, 'es_directo' => false,
                'version' => $primary->version + 1]);
            AuditLogger::tenant(null, 'UPDATE', 'componente_evaluacion', (string) $primary->id,
                $before, $primary->toArray(), 'Conversión al primer preinforme; valores originales de notas intactos.');
        }
        $period->update(['configuracion_notas' => ['usar_preinformes' => true,
            'modo' => 'WEIGHTED_AVERAGE', 'fechas_estrictas' => true],
            'version_notas' => $period->version_notas + 1]);
        AuditLogger::tenant(null, 'UPDATE', 'configuracion_preinformes', (string) $period->id,
            ['configuracion' => null], ['configuracion' => $period->configuracion_notas],
            'Conversión auditada de planillas previas; las definitivas podrán variar al completar los demás preinformes.');

        $afterActivities = DB::table('actividades_evaluacion')->whereIn('id', $activities->pluck('id'))->count();
        $afterGrades = DB::table('calificaciones')->whereIn('actividad_id', $activities->pluck('id'))->count();
        if ($afterActivities !== $activities->count() || $afterGrades !== $gradeCount) {
            throw new RuntimeException('La conservación de actividades o notas falló; se revierte la transacción.');
        }
    });

    return ['modo' => 'aplicado', ...$preview];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
