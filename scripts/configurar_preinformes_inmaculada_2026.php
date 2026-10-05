<?php

declare(strict_types=1);

/**
 * Configura cuatro preinformes ponderados en períodos históricos vacíos.
 * No toca el cuarto período, que contiene planillas anteriores por reconciliar.
 * Por defecto es vista previa; usar --apply después de respaldar el tenant.
 */

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
    if (! $year) {
        throw new RuntimeException('No se encontró el año 2026 en curso.');
    }
    $periods = Periodo::where('ano_lectivo_id', $year->id)->whereIn('orden', [1, 2, 3])->orderBy('orden')->get();
    if ($periods->count() !== 3) {
        throw new RuntimeException('No están los tres períodos históricos esperados.');
    }
    $preview = [];
    foreach ($periods as $period) {
        $existing = Preinforme::where('periodo_id', $period->id)->count();
        $activities = DB::table('actividades_evaluacion as a')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
            ->where('c.periodo_id', $period->id)->count();
        if ($period->estado !== 'cerrado' || $period->configuracion_notas !== null || $existing || $activities) {
            throw new RuntimeException("El período {$period->nombre} ya tiene configuración o actividades; no se cambió.");
        }
        $preview[] = ['periodo' => $period->nombre, 'estado' => $period->estado,
            'inicio' => $period->fecha_inicio->toDateString(), 'fin' => $period->fecha_fin->toDateString(),
            'preinformes_nuevos' => 4, 'pesos' => [20, 25, 25, 30]];
    }
    if (! $apply) {
        return ['modo' => 'vista_previa', 'periodos' => $preview];
    }

    DB::transaction(function () use ($periods) {
        foreach ($periods as $period) {
            $period = Periodo::lockForUpdate()->findOrFail($period->id);
            $before = $period->toArray();
            $period->update(['estado' => 'abierto', 'reapertura_manual' => true]);
            AuditLogger::tenant(null, 'UPDATE', 'periodo', (string) $period->id,
                $before, $period->toArray(), 'Reapertura excepcional para cargar preinformes históricos sintéticos; fecha de auditoría actual.');

            $start = Carbon::parse($period->fecha_inicio);
            $days = $start->diffInDays(Carbon::parse($period->fecha_fin)) + 1;
            foreach ([20, 25, 25, 30] as $index => $weight) {
                $from = $start->copy()->addDays((int) floor($days * $index / 4));
                $to = $index === 3 ? Carbon::parse($period->fecha_fin)
                    : $start->copy()->addDays((int) floor($days * ($index + 1) / 4) - 1);
                $pre = Preinforme::create(['periodo_id' => $period->id,
                    'nombre' => ['Primer preinforme', 'Segundo preinforme', 'Tercer preinforme', 'Cuarto preinforme'][$index],
                    'orden' => $index + 1, 'peso' => $weight,
                    'fecha_inicio' => $from->toDateString(), 'fecha_fin' => $to->toDateString()]);
                AuditLogger::tenant(null, 'CREATE', 'preinforme', (string) $pre->id,
                    null, $pre->toArray(), 'Carga sintética local para auditoría académica 2026.');
            }
            $period->update(['configuracion_notas' => ['usar_preinformes' => true,
                'modo' => 'WEIGHTED_AVERAGE', 'fechas_estrictas' => true],
                'version_notas' => $period->version_notas + 1]);
            AuditLogger::tenant(null, 'UPDATE', 'configuracion_preinformes', (string) $period->id,
                ['configuracion' => null], ['configuracion' => $period->configuracion_notas],
                'Configuración histórica sintética; fecha de auditoría actual.');

            $beforeClose = $period->toArray();
            $period->update(['estado' => 'cerrado', 'reapertura_manual' => false]);
            AuditLogger::tenant(null, 'UPDATE', 'periodo', (string) $period->id,
                $beforeClose, $period->toArray(), 'Restauración del estado cerrado tras configurar preinformes.');
        }
    });

    return ['modo' => 'aplicado', 'periodos' => $preview];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
