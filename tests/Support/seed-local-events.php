<?php

declare(strict_types=1);

// Datos de demostración SOLO para la base local de Inmaculada. No envía correos.
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
    fwrite(STDERR, "Este cargador solo funciona en el entorno local.\n");
    exit(1);
}
$options = getopt('', ['inspect', 'apply']);
if (isset($options['inspect']) === isset($options['apply'])) {
    fwrite(STDERR, "Usa --inspect o --apply, nunca ambos.\n");
    exit(1);
}

$topics = [
    'actividad' => [
        ['Lectura compartida', 'Lectura guiada y conversación sobre personajes, ideas y vocabulario.'],
        ['Taller de experimentos sencillos', 'Exploración de fenómenos cotidianos con materiales de aula y registro de observaciones.'],
        ['Jornada de escritura creativa', 'Producción de relatos breves y lectura voluntaria de los textos elaborados.'],
        ['Estaciones de pensamiento matemático', 'Resolución colaborativa de problemas mediante juegos y material concreto.'],
        ['Exploración del entorno escolar', 'Observación de espacios del colegio y conversación sobre su cuidado.'],
        ['Práctica de expresión oral', 'Presentaciones cortas para fortalecer la comunicación y la escucha respetuosa.'],
        ['Laboratorio de arte y color', 'Creación de composiciones con distintas técnicas y socialización de resultados.'],
        ['Actividad de hábitos saludables', 'Diálogo y ejercicios prácticos sobre alimentación, movimiento y descanso.'],
        ['Club de lectura en el aula', 'Elección de lecturas apropiadas para el grupo y conversación sobre lo leído.'],
        ['Proyecto de cuidado ambiental', 'Clasificación de residuos y propuestas para cuidar los espacios comunes.'],
        ['Taller de convivencia', 'Dinámicas para practicar el diálogo, la colaboración y la resolución de conflictos.'],
        ['Muestra de aprendizaje', 'Espacio para compartir trabajos desarrollados durante las clases.'],
    ],
    'reunion' => [
        ['Encuentro de seguimiento académico', 'Conversación sobre avances del grupo y acciones de acompañamiento en casa.'],
        ['Reunión de familias y docentes', 'Espacio de diálogo sobre el trabajo escolar y preguntas de las familias.'],
        ['Orientación del periodo académico', 'Presentación de objetivos del periodo y acuerdos de comunicación.'],
        ['Diálogo sobre convivencia', 'Revisión conjunta de hábitos de convivencia y compromisos del grupo.'],
        ['Encuentro de representantes', 'Conversación para recoger inquietudes y propuestas de la comunidad educativa.'],
        ['Reunión informativa de actividades', 'Información general sobre las actividades escolares previstas para el grupo.'],
        ['Seguimiento de proyectos de aula', 'Socialización de avances y próximos pasos de los proyectos del grupo.'],
        ['Espacio de preguntas para familias', 'Atención de preguntas generales relacionadas con la vida escolar.'],
    ],
    'celebracion' => [
        ['Jornada de talentos', 'Presentaciones artísticas y reconocimiento de habilidades de los estudiantes.'],
        ['Encuentro de lectura y cultura', 'Actividades culturales para disfrutar la lectura en comunidad.'],
        ['Celebración de logros del grupo', 'Reconocimiento de avances y esfuerzo colectivo durante el periodo.'],
        ['Muestra artística escolar', 'Exposición de trabajos y expresiones artísticas elaboradas en clase.'],
        ['Festival de juegos cooperativos', 'Juegos para fortalecer la participación y el trabajo en equipo.'],
        ['Encuentro de cierre de proyectos', 'Socialización y celebración del trabajo realizado en los proyectos escolares.'],
        ['Día de la creatividad', 'Espacio de expresión para compartir ideas, dibujos y pequeñas creaciones.'],
        ['Jornada de integración escolar', 'Actividades de integración y encuentro entre estudiantes.'],
    ],
    'aviso' => [
        ['Recordatorio de materiales de clase', 'Revisar los materiales habituales antes de asistir a la siguiente actividad.'],
        ['Información sobre la agenda escolar', 'Consultar el calendario para conocer las próximas actividades del grupo.'],
        ['Recomendaciones para el cuidado de libros', 'Mantener los materiales de lectura identificados y en buen estado.'],
        ['Aviso de convivencia escolar', 'Recordar los acuerdos de respeto y cuidado de los espacios compartidos.'],
        ['Orientaciones para las familias', 'Revisar las comunicaciones escolares y acompañar las actividades del estudiante.'],
        ['Preparación de actividades de aula', 'Consultar con anticipación las indicaciones de las próximas actividades.'],
        ['Recordatorio de participación', 'Animar a los estudiantes a participar y presentar sus inquietudes en clase.'],
        ['Consulta del calendario', 'Verificar fechas y horarios de las actividades publicadas en la agenda.'],
    ],
];
$pattern = ['actividad', 'reunion', 'actividad', 'aviso', 'actividad', 'celebracion', 'reunion', 'actividad',
    'aviso', 'actividad', 'reunion', 'celebracion', 'actividad', 'aviso', 'reunion', 'actividad'];
$school = Tenant::where('slug', 'inmaculada')->where('status', Tenant::STATUS_ACTIVE)->firstOrFail();

try {
    $result = $school->run(function () use ($options, $topics, $pattern): array {
        $year = AnoLectivo::where('nombre', '2026')->where('estado', 'en_curso')->firstOrFail();
        $groups = Grupo::with('grado')->where('ano_lectivo_id', $year->id)
            ->where('estado', 'activo')->orderBy('id')->get();
        if ($groups->isEmpty()) throw new RuntimeException('No hay grupos activos para 2026.');
        $actor = User::where('status', 'active')->where('role', 'rector')->get()
            ->first(fn (User $user) => $user->can('eventos.gestionar') && $user->can('eventos.publicar_institucional'));
        if (! $actor) throw new RuntimeException('No hay rector autorizado para publicar.');

        $days = [];
        for ($day = Carbon::parse($year->fecha_inicio)->startOfDay(), $end = Carbon::parse($year->fecha_fin)->startOfDay();
            $day->lte($end); $day->addDay()) {
            if ($day->isWeekday()) $days[] = $day->toDateString();
        }
        if (count($days) < 100) throw new RuntimeException('El año lectivo no tiene suficientes días hábiles.');
        $existing = Evento::withTrashed()->count();
        $mailBefore = DB::table('evento_correos')->count();
        if (isset($options['inspect'])) return ['planned' => 320, 'existing_events' => $existing,
            'groups' => $groups->count(), 'school_days' => count($days), 'mail_rows' => $mailBefore, 'applied' => false];
        if ($existing !== 0) throw new RuntimeException('Ya existen eventos; no se añadirán ejemplos ni se duplicarán registros.');

        $counts = array_fill_keys(array_keys($topics), 0);
        $access = app(EventAccess::class);
        $mail = app(EventMailOutbox::class);
        DB::transaction(function () use ($groups, $actor, $days, $topics, $pattern, $access, $mail, $mailBefore, &$counts): void {
            for ($i = 0; $i < 320; $i++) {
                $category = $pattern[$i % count($pattern)];
                $topic = $topics[$category][$counts[$category] % count($topics[$category])];
                $institutional = $i % 11 === 0;
                $group = $groups[$i % $groups->count()];
                $name = $institutional ? $topic[0] : $topic[0].' — '.$group->grado->nombre.' '.$group->nombre;
                $scope = $institutional ? 'Evento para toda la comunidad estudiantil.'
                    : 'Actividad dirigida al grupo '.$group->grado->nombre.' '.$group->nombre.'.';
                $times = match ($category) {
                    'actividad' => ['08:00', '09:00'], 'reunion' => ['15:00', '16:00'],
                    'celebracion' => ['09:00', '11:00'], default => [null, null],
                };
                $access->authorizeWrite($actor, ['institucional' => $institutional,
                    'grupo_ids' => $institutional ? [] : [$group->id], 'materia_id' => null]);
                $event = Evento::create([
                    'titulo' => $name, 'descripcion' => $topic[1].' '.$scope,
                    'fecha' => $days[$i % count($days)], 'hora_inicio' => $times[0], 'hora_fin' => $times[1],
                    'categoria' => $category, 'institucional' => $institutional, 'materia_id' => null,
                    'created_by' => $actor->id, 'estado' => 'borrador', 'version' => 1,
                    'enviar_correo' => false,
                ]);
                if (! $institutional) $event->grupos()->sync([$group->id]);
                AuditLogger::tenant($actor, 'CREATE', 'evento', (string) $event->id, null, $event->toArray());
                $event->estado = 'publicado';
                $event->publicado_en = now('UTC');
                $event->version = 2;
                $event->save();
                $mail->prepare($event); // Desactivado: prueba el mismo guardarraíl de publicación.
                AuditLogger::tenant($actor, 'PUBLISH', 'evento', (string) $event->id, null,
                    ['enviar_correo' => false, 'origen' => 'ejemplos_locales']);
                $counts[$category]++;
            }
            if (DB::table('evento_correos')->count() !== $mailBefore) {
                throw new RuntimeException('Se detectaron destinatarios nuevos; se cancela la carga.');
            }
        });
        return ['planned' => 320, 'created' => array_sum($counts), 'by_category' => $counts,
            'groups' => $groups->count(), 'school_days' => count($days), 'mail_rows_before' => $mailBefore,
            'mail_rows_after' => DB::table('evento_correos')->count(), 'applied' => true];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Carga cancelada: '.$error->getMessage().PHP_EOL);
    exit(1);
}
