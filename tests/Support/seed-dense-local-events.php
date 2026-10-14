<?php

declare(strict_types=1);

// Amplía la agenda de demostración local. Nunca envía correos ni toca eventos ajenos.
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Evento;
use App\Models\Academico\Grupo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventAccess;
use App\Services\EventMailOutbox;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment('local')) {
    fwrite(STDERR, "Este cargador solo puede ejecutarse localmente.\n"); exit(1);
}
$options = getopt('', ['inspect', 'apply']);
if (isset($options['inspect']) === isset($options['apply'])) {
    fwrite(STDERR, "Selecciona solo --inspect o --apply.\n"); exit(1);
}

$topics = [
    'actividad' => ['Lectura compartida', 'Laboratorio de ciencias', 'Taller de escritura', 'Exploración matemática',
        'Proyecto ambiental', 'Círculo de conversación', 'Taller artístico', 'Juegos cooperativos',
        'Ruta de aprendizaje', 'Huerta escolar', 'Expresión oral', 'Práctica de convivencia'],
    'reunion' => ['Encuentro de familias', 'Seguimiento académico', 'Diálogo de convivencia',
        'Orientación del periodo', 'Consejo de grupo', 'Conversación con representantes',
        'Planeación de proyectos', 'Revisión de avances'],
    'celebracion' => ['Muestra cultural', 'Jornada de talentos', 'Encuentro artístico', 'Reconocimiento de logros',
        'Festival de lectura', 'Jornada de integración', 'Exposición de proyectos', 'Música y expresión'],
    'aviso' => ['Agenda de la semana', 'Materiales de clase', 'Orientaciones a familias',
        'Cuidado de los espacios', 'Información académica', 'Recordatorio de participación',
        'Organización de actividades', 'Comunicación del grupo'],
];
$themes = ['nuevas ideas', 'trabajo en equipo', 'lecturas del entorno', 'cuidado del colegio',
    'creatividad cotidiana', 'preguntas y descubrimientos', 'retos compartidos', 'expresión de emociones',
    'hábitos de estudio', 'relatos y personajes', 'pensamiento lógico', 'proyectos del aula',
    'convivencia respetuosa', 'exploración de formas', 'cultura escolar', 'aprendizajes del periodo',
    'familia y escuela', 'arte y movimiento', 'observación de la naturaleza', 'comunicación clara',
    'solución de problemas', 'lectura en voz alta', 'ideas para mejorar', 'participación estudiantil',
    'experiencias de clase', 'juegos y estrategias', 'prácticas saludables', 'intercambio de saberes',
    'experimentos sencillos', 'creación colectiva', 'progreso del grupo', 'desarrollo de habilidades'];
$pattern = ['actividad', 'reunion', 'actividad', 'aviso', 'actividad', 'celebracion', 'reunion', 'actividad',
    'aviso', 'actividad', 'reunion', 'celebracion', 'actividad', 'aviso', 'reunion', 'actividad'];
$school = Tenant::where('slug', 'inmaculada')->where('status', Tenant::STATUS_ACTIVE)->firstOrFail();

try {
    $result = $school->run(function () use ($options, $topics, $themes, $pattern): array {
        $year = AnoLectivo::where('nombre', '2026')->where('estado', 'en_curso')->firstOrFail();
        $groups = Grupo::with('grado')->where('ano_lectivo_id', $year->id)->where('estado', 'activo')->orderBy('id')->get();
        if ($groups->isEmpty()) throw new RuntimeException('No hay grupos activos.');
        $maria = User::where('status', 'active')->where('role', 'rector')->whereRaw('LOWER(name) = ?', ['maria'])->firstOrFail();
        if (! $maria->can('eventos.gestionar') || ! $maria->can('eventos.publicar_institucional')) {
            throw new RuntimeException('María no tiene autorización de rector.');
        }
        $days = [];
        for ($date = Carbon::parse('2026-10-01'), $end = Carbon::parse('2026-10-31'); $date->lte($end); $date->addDay()) {
            if ($date->isWeekday() && $date->betweenIncluded($year->fecha_inicio, $year->fecha_fin)) {
                $days[] = $date->toDateString();
            }
        }
        $targets = [];
        $needed = 0;
        foreach ($days as $date) {
            $target = $date === '2026-10-14' ? 300 : 20;
            $existing = Evento::whereDate('fecha', $date)->where('estado', 'publicado')->count();
            $targets[$date] = ['target' => $target, 'existing' => $existing, 'missing' => max(0, $target - $existing)];
            $needed += $targets[$date]['missing'];
        }
        $mailBefore = DB::table('evento_correos')->count();
        if (isset($options['inspect'])) return ['days' => count($days), 'october_missing_publications' => $needed,
            'special_day' => $targets['2026-10-14'], 'maria_drafts' => Evento::where('created_by', $maria->id)
                ->where('estado', 'borrador')->count(), 'mail_rows' => $mailBefore, 'applied' => false];

        $counts = array_fill_keys(array_keys($topics), 0);
        $drafts = 0;
        $access = app(EventAccess::class);
        $mail = app(EventMailOutbox::class);
        DB::transaction(function () use ($days, $targets, $groups, $maria, $topics, $themes, $pattern,
            $access, $mail, $mailBefore, &$counts, &$drafts): void {
            foreach ($days as $dayIndex => $date) {
                for ($slot = $targets[$date]['existing']; $slot < $targets[$date]['target']; $slot++) {
                    $category = $pattern[($slot + $dayIndex * 7) % count($pattern)];
                    $group = $groups[($slot + $dayIndex * 7) % $groups->count()];
                    $theme = $themes[intdiv($slot, count($topics[$category])) % count($themes)];
                    $name = $topics[$category][$slot % count($topics[$category])].': '.$theme;
                    $institutional = ($slot + $dayIndex) % 13 === 0;
                    if (! $institutional) $name .= ' — '.$group->grado->nombre.' '.$group->nombre;
                    $scope = $institutional ? 'Dirigido a toda la comunidad estudiantil.'
                        : 'Dirigido al grupo '.$group->grado->nombre.' '.$group->nombre.'.';
                    $access->authorizeWrite($maria, ['institucional' => $institutional,
                        'grupo_ids' => $institutional ? [] : [$group->id], 'materia_id' => null]);
                    $event = Evento::create(['titulo' => $name,
                        'descripcion' => 'Espacio escolar sobre '.$theme.'. Se compartirán orientaciones y actividades acordes con el grupo. '.$scope,
                        'fecha' => $date, 'hora_inicio' => null, 'hora_fin' => null,
                        'categoria' => $category, 'institucional' => $institutional, 'materia_id' => null,
                        'created_by' => $maria->id, 'estado' => 'borrador', 'version' => 1, 'enviar_correo' => false]);
                    if (! $institutional) $event->grupos()->sync([$group->id]);
                    AuditLogger::tenant($maria, 'CREATE', 'evento', (string) $event->id, null, $event->toArray());
                    $event->estado = 'publicado';
                    $event->publicado_en = now('UTC');
                    $event->version = 2;
                    $event->save();
                    $mail->prepare($event);
                    AuditLogger::tenant($maria, 'PUBLISH', 'evento', (string) $event->id, null,
                        ['enviar_correo' => false, 'origen' => 'agenda_local_densa']);
                    $counts[$category]++;
                }
            }
            for ($i = 0; $i < 12; $i++) {
                $group = $groups[$i % $groups->count()];
                $date = $days[($i * 2) % count($days)];
                $name = 'Planeación de '.mb_strtolower($topics['actividad'][$i]).' — '.$group->grado->nombre.' '.$group->nombre;
                if (Evento::where('created_by', $maria->id)->whereDate('fecha', $date)->where('titulo', $name)->exists()) continue;
                $event = Evento::create(['titulo' => $name,
                    'descripcion' => 'Propuesta en elaboración para el grupo. Falta revisar y decidir su publicación.',
                    'fecha' => $date, 'hora_inicio' => null, 'hora_fin' => null,
                    'categoria' => 'actividad', 'institucional' => false, 'materia_id' => null,
                    'created_by' => $maria->id, 'estado' => 'borrador', 'version' => 1, 'enviar_correo' => false]);
                $event->grupos()->sync([$group->id]);
                AuditLogger::tenant($maria, 'CREATE', 'evento', (string) $event->id, null, $event->toArray());
                $drafts++;
            }
            if (DB::table('evento_correos')->count() !== $mailBefore) {
                throw new RuntimeException('Cambió la cantidad de destinatarios; se cancela toda la carga.');
            }
        });

        return ['created_published' => array_sum($counts), 'by_category' => $counts, 'created_maria_drafts' => $drafts,
            'october_weekdays' => count($days), 'special_day_target' => 300,
            'mail_rows_before' => $mailBefore, 'mail_rows_after' => DB::table('evento_correos')->count(), 'applied' => true];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Carga cancelada: '.$error->getMessage().PHP_EOL); exit(1);
}
