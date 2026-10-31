<?php

declare(strict_types=1);

/**
 * Datos pedagógicos de demostración para todos los grupos y períodos de Inmaculada 2026.
 * Solo entorno local: primero inventario, luego --apply con respaldo PostgreSQL.
 * No cambia el estado ni las fechas de los períodos y no modifica boletines emitidos.
 */

use App\Models\Tenant;
use App\Models\User;
use App\Services\IndicatorHtml;
use App\Services\IndicatorPolicy;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
if (! app()->environment('local')) {
    throw new RuntimeException('La carga de muestra solo se permite en desarrollo local.');
}
$catalog = require __DIR__.'/data/indicadores_inmaculada_2026.php';
$focusByGrade = require __DIR__.'/data/focos_indicadores_inmaculada_2026.php';
$tenant = Tenant::query()->where('slug', 'inmaculada')->where('name', 'Inmaculada')->firstOrFail();
if ($tenant->tipo === Tenant::TIPO_SEDE) {
    throw new RuntimeException('El destino debe ser el colegio matriz, no una sede.');
}

$result = $tenant->run(function () use ($apply, $catalog, $focusByGrade): array {
    $config = DB::connection()->getConfig();
    if (($config['driver'] ?? null) !== 'pgsql'
        || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('La base de Inmaculada no es PostgreSQL local.');
    }
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->where('estado', 'en_curso')
        ->whereNull('deleted_at')->first() ?? throw new RuntimeException('No existe el año 2026 en curso.');
    $periods = DB::table('periodos')->where('ano_lectivo_id', $year->id)->orderBy('orden')->get();
    if ($periods->pluck('orden')->all() !== [1, 2, 3, 4]) {
        throw new RuntimeException('Los cuatro períodos esperados no coinciden.');
    }
    if (IndicatorPolicy::forYear($year->id)['alcance'] !== 'grupo') {
        throw new RuntimeException('El año no usa indicadores por grupo.');
    }
    // Configure the local audit actor without publishing a personal contact address.
    $actorEmail = trim((string) getenv('INDICATOR_DEMO_ACTOR_EMAIL'));
    $actor = $actorEmail !== '' ? User::where('email', $actorEmail)->first() : null;
    if (! $actor || $actor->status !== 'active' || ! $actor->hasRole('rector')
        || ! $actor->can('indicadores.base.gestionar')) {
        throw new RuntimeException('Configura INDICATOR_DEMO_ACTOR_EMAIL con un rector activo autorizado para atribuir la carga.');
    }
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->where('g.estado', 'activo')
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')->orderBy('d.id')->orderBy('g.id')
        ->get(['g.id', 'g.grado_id', 'g.nombre as grupo', 'd.nombre as grado']);
    if ($groups->count() !== 23) {
        throw new RuntimeException('La cantidad de grupos cambió; revisa el inventario antes de aplicar.');
    }
    if ($groups->pluck('grado')->unique()->diff(array_keys($focusByGrade))->isNotEmpty()) {
        throw new RuntimeException('Falta la progresión de uno o más grados.');
    }
    $subjectsByGrade = $groups->pluck('grado_id')->unique()->mapWithKeys(function (int $gradeId) use ($year): array {
        $subjects = DB::table('materias_curriculares as mc')
            ->join('materias as m', 'm.id', '=', 'mc.materia_id')
            ->where('mc.ano_lectivo_id', $year->id)->where('mc.grado_id', $gradeId)
            ->where('m.estado', 'activo')->orderBy('m.nombre')->get(['m.id', 'm.nombre']);

        return [$gradeId => $subjects];
    });
    $used = $subjectsByGrade->flatMap(fn ($subjects) => $subjects->pluck('nombre'))->unique()->values();
    $missing = $used->diff(array_keys($catalog));
    if ($missing->isNotEmpty()) {
        throw new RuntimeException('Faltan objetivos para: '.$missing->implode(', '));
    }
    foreach ($catalog as $name => $entries) {
        if (count($entries) !== 4 || collect($entries)->contains(fn ($pair) => count($pair) !== 2
            || trim($pair[0]) === '' || trim($pair[1]) === '')) {
            throw new RuntimeException("Plantilla incompleta: {$name}.");
        }
    }
    $existing = DB::table('indicadores_contenidos_grupo')->where('ano_lectivo_id', $year->id)
        ->get()->keyBy(fn ($row) => $row->grupo_id.':'.$row->materia_id.':'.$row->periodo_id);
    $html = app(IndicatorHtml::class);
    $targets = [];
    $counts = ['crear' => 0, 'llenar_vacio' => 0, 'conservar_existente' => 0];
    $byGrade = [];
    foreach ($groups as $group) {
        foreach ($subjectsByGrade[$group->grado_id] as $subject) {
            foreach ($periods as $period) {
                $pair = $catalog[$subject->nombre][$period->orden - 1];
                $content = '<ul><li>'.htmlspecialchars($pair[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .'</li><li>'.htmlspecialchars($pair[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>';
                $focus = $focusByGrade[$group->grado][$subject->nombre] ?? null;
                if ($focus) {
                    $content .= '<li>'.htmlspecialchars($focus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</li>';
                }
                $content .= '</ul>';
                $content = $html->sanitize($content);
                if ($html->plain($content) === '') {
                    throw new RuntimeException('Se generó un indicador vacío.');
                }
                $key = $group->id.':'.$subject->id.':'.$period->id;
                $current = $existing->get($key);
                $action = $current === null ? 'crear'
                    : ($html->plain($current->texto_html) === '' ? 'llenar_vacio' : 'conservar_existente');
                $counts[$action]++;
                $byGrade[$group->grado] = ($byGrade[$group->grado] ?? 0) + 1;
                $targets[] = compact('group', 'subject', 'period', 'content', 'current', 'action', 'key');
            }
        }
    }
    $backupPath = null;
    if ($apply) {
        $dumpBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_dump.exe';
        $restoreBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_restore.exe';
        if (! is_file($dumpBinary) || ! is_file($restoreBinary)) {
            throw new RuntimeException('Faltan las herramientas PostgreSQL 16 para respaldar.');
        }
        $backupDir = base_path('storage/app/local-sample-backups');
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0700, true) && ! is_dir($backupDir)) {
            throw new RuntimeException('No se pudo crear el directorio de respaldos.');
        }
        $backupPath = $backupDir.'/inmaculada-indicadores-todos-periodos-'.date('Ymd-His').'.dump';
        (new Process([$dumpBinary, '--format=custom', '--no-owner', '--host='.(string) $config['host'],
            '--port='.(string) $config['port'], '--username='.(string) $config['username'],
            '--file='.$backupPath, (string) $config['database']], base_path(),
            ['PGPASSWORD' => (string) $config['password']], null, 300))->mustRun();
        if (! is_file($backupPath) || filesize($backupPath) < 1024) {
            throw new RuntimeException('El respaldo no es válido; no se modificó nada.');
        }
        (new Process([$restoreBinary, '--list', $backupPath], base_path(), null, null, 60))->mustRun();

        DB::transaction(function () use ($targets, $actor, $year, $html): void {
            DB::table('anos_lectivos')->where('id', $year->id)->lockForUpdate()->first();
            $now = now();
            foreach ($targets as $target) {
                ['group' => $group, 'subject' => $subject, 'period' => $period,
                    'content' => $content, 'current' => $snapshot, 'key' => $key] = $target;
                $query = DB::table('indicadores_contenidos_grupo')->where('grupo_id', $group->id)
                    ->where('materia_id', $subject->id)->where('periodo_id', $period->id);
                $current = (clone $query)->lockForUpdate()->first();
                if (($current?->version ?? null) !== ($snapshot?->version ?? null)
                    || ($current?->texto_html ?? null) !== ($snapshot?->texto_html ?? null)) {
                    throw new RuntimeException("Cambió el indicador {$key} desde el inventario; se canceló toda la carga.");
                }
                if ($current && $html->plain($current->texto_html) !== '') {
                    continue;
                }
                $version = ($current?->version ?? 0) + 1;
                $values = ['texto_html' => $content, 'version' => $version,
                    'updated_by' => $actor->id, 'updated_at' => $now];
                if ($current) {
                    $query->update($values);
                } else {
                    DB::table('indicadores_contenidos_grupo')->insert([
                        'ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                        'materia_id' => $subject->id, 'periodo_id' => $period->id,
                        ...$values, 'created_at' => $now,
                    ]);
                }
                AuditLogger::tenant($actor, $current ? 'UPDATE' : 'CREATE',
                    'indicador_contenido_grupo', $key,
                    $current ? ['version' => $current->version, 'texto_html' => $current->texto_html] : null,
                    ['version' => $version, 'texto_html' => $content],
                    'Carga local de muestra para los cuatro períodos; sin cambiar el estado del período.');
            }
        });
    }

    return [
        'modo' => $apply ? 'aplicado' : 'plan', 'colegio' => 'Inmaculada',
        'base' => DB::connection()->getDatabaseName(),
        'grupos' => $groups->count(), 'periodos' => $periods->pluck('estado', 'orden')->all(),
        'celdas' => count($targets), 'acciones' => $counts, 'celdas_por_grado' => $byGrade,
        'conteo_final' => DB::table('indicadores_contenidos_grupo')->where('ano_lectivo_id', $year->id)->count(),
        'respaldo' => $backupPath,
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
