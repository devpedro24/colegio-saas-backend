<?php

declare(strict_types=1);

/**
 * Carga demostrativa local de indicadores y observaciones, siempre con plan previo.
 * php scripts/cargar_indicadores_observaciones_inmaculada_2026.php [--apply]
 */

use App\Http\Controllers\Api\Academico\BoletinObservacionController;
use App\Http\Controllers\Api\Academico\IndicadorController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$goals = [
    'Convivencia y Autonomía' => ['Participa en acuerdos sencillos de convivencia y respeta los turnos de juego.', 'Organiza sus materiales y toma pequeñas decisiones con autonomía.'],
    'Dimensión Cognitiva' => ['Clasifica objetos por sus características y explica el criterio que utilizó.', 'Reconoce patrones y propone una continuación mediante objetos o dibujos.'],
    'Dimensión Comunicativa' => ['Expresa ideas y experiencias con palabras, gestos o representaciones gráficas.', 'Escucha relatos breves e identifica personajes y hechos principales.'],
    'Dimensión Corporal' => ['Coordina movimientos en juegos que incluyen desplazamiento y equilibrio.', 'Reconoce hábitos de cuidado del cuerpo durante las actividades escolares.'],
    'Educación Artística' => ['Explora colores, formas y materiales para expresar una idea propia.', 'Comparte el sentido de una creación artística y respeta las de sus compañeros.'],
    'Educación Física' => ['Participa en juegos motores aplicando acuerdos de seguridad y cooperación.', 'Practica secuencias de movimiento y reconoce la importancia del calentamiento.'],
    'Educación Religiosa' => ['Reconoce acciones de cuidado y solidaridad en su entorno cercano.', 'Escucha con respeto las experiencias y creencias compartidas en el grupo.'],
    'Ética y Valores' => ['Propone formas respetuosas de resolver desacuerdos cotidianos.', 'Cumple acuerdos de convivencia y reconoce el efecto de sus decisiones.'],
    'Exploración del Medio' => ['Observa elementos del entorno y comunica semejanzas y diferencias.', 'Formula preguntas sobre fenómenos cercanos y busca respuestas con acompañamiento.'],
    'Expresión Artística' => ['Combina materiales y técnicas para representar situaciones de su entorno.', 'Describe su proceso creativo y aprecia distintas formas de expresión.'],
    'Tecnología e Informática' => ['Ordena pasos sencillos para resolver una tarea con apoyo de herramientas.', 'Aplica acuerdos de uso responsable de dispositivos y materiales tecnológicos.'],
    'Ciencias Naturales' => ['Relaciona las necesidades de los seres vivos con las condiciones de su entorno.', 'Registra observaciones y explica un cambio natural con sus propias palabras.'],
    'Ciencias Sociales' => ['Reconoce espacios y personas de su comunidad y sus responsabilidades.', 'Compara situaciones de convivencia y propone acciones para cuidar lo común.'],
    'Inglés Básico' => ['Comprende vocabulario cotidiano en instrucciones y conversaciones breves.', 'Utiliza expresiones sencillas en inglés para presentarse y describir su entorno.'],
    'Lengua Castellana' => ['Identifica ideas principales en textos apropiados para su nivel.', 'Organiza y comunica sus ideas en producciones orales o escritas breves.'],
    'Matemáticas' => ['Explica estrategias para resolver situaciones de cantidad y comparación.', 'Reconoce patrones o relaciones y verifica sus respuestas con ejemplos.'],
    'Taller de Lectura y Escritura' => ['Lee textos breves y conversa sobre sus personajes e ideas.', 'Revisa un escrito propio para comunicar con mayor claridad lo que quiere contar.'],
    'Álgebra y Geometría' => ['Representa relaciones entre cantidades mediante expresiones y figuras geométricas.', 'Explica un procedimiento algebraico o geométrico y comprueba su resultado.'],
    'Biología y Ambiente' => ['Relaciona la estructura y función de los seres vivos con su entorno.', 'Analiza prácticas cotidianas que afectan el ambiente y propone acciones de cuidado.'],
    'Historia y Geografía' => ['Ubica procesos históricos en el tiempo y relaciona sus cambios con el territorio.', 'Interpreta fuentes y mapas para explicar características de una comunidad.'],
    'Inglés Intermedio' => ['Comprende ideas generales en textos y conversaciones sobre situaciones conocidas.', 'Produce mensajes breves en inglés con vocabulario y estructuras acordes al propósito.'],
    'Lengua y Literatura' => ['Interpreta textos literarios y argumenta una lectura con evidencias del texto.', 'Revisa sus producciones escritas considerando intención, organización y claridad.'],
    'Proyecto Integrador' => ['Formula una pregunta de trabajo y organiza tareas para desarrollar un proyecto.', 'Comunica hallazgos y reflexiona sobre los ajustes realizados durante el proyecto.'],
    'Actividad Física' => ['Diseña una rutina de actividad física acorde con sus objetivos de bienestar.', 'Evalúa prácticas de movimiento y descanso para cuidar su salud.'],
    'Biología' => ['Explica procesos de los seres vivos a partir de evidencias y modelos.', 'Analiza relaciones entre organismos, ambiente y acciones humanas.'],
    'Ciencias Económicas y Políticas' => ['Relaciona decisiones económicas y políticas con situaciones de su entorno.', 'Argumenta una postura frente a un asunto público usando información verificable.'],
    'Filosofía' => ['Formula preguntas filosóficas y distingue razones de opiniones.', 'Construye argumentos propios y dialoga respetuosamente con perspectivas diferentes.'],
    'Física' => ['Representa fenómenos físicos mediante modelos y magnitudes.', 'Contrasta predicciones con observaciones y explica posibles diferencias.'],
    'Inglés Avanzado' => ['Analiza ideas principales y detalles en textos de diversos contextos en inglés.', 'Sostiene una posición en inglés con argumentos y vocabulario pertinentes.'],
    'Investigación Escolar' => ['Delimita un problema investigable y selecciona un método apropiado.', 'Presenta resultados citando sus fuentes y reconociendo las limitaciones del trabajo.'],
    'Literatura y Comunicación' => ['Analiza recursos expresivos y contextos en obras literarias.', 'Produce textos para distintos públicos y revisa su coherencia y propósito.'],
    'Matemáticas Aplicadas' => ['Modela situaciones reales con herramientas matemáticas y explica sus supuestos.', 'Interpreta resultados y verifica si tienen sentido en el contexto del problema.'],
    'Química' => ['Describe transformaciones de la materia con modelos y lenguaje químico.', 'Interpreta evidencias experimentales siguiendo normas de seguridad.'],
];
$preschoolNotes = [
    'En este cuarto período participó en propuestas de exploración, juego y expresión. Se sugiere continuar fortaleciendo la escucha y el respeto de turnos en actividades compartidas.',
    'Las experiencias de movimiento, arte y observación ofrecen oportunidades para expresar ideas. Conviene seguir acompañando la autonomía al organizar materiales y participar en grupo.',
    'El juego y los relatos permiten construir nuevas formas de comunicación. Como siguiente paso, se recomienda practicar la descripción de lo observado con palabras, gestos y dibujos.',
    'Las actividades de clasificación y convivencia favorecen el aprendizaje cotidiano. Se propone continuar con juegos de secuencias, acuerdos y cuidado de los materiales.',
];
$primaryNotes = [
    'Durante el cuarto período trabajó en actividades de lectura, resolución de problemas y colaboración. Se recomienda mantener la lectura frecuente y explicar los pasos usados al resolver una tarea.',
    'Las actividades del período permitieron relacionar contenidos con situaciones cercanas. Conviene seguir fortaleciendo la organización del trabajo y la revisión de las respuestas.',
    'En este período se propusieron ejercicios de comunicación y pensamiento matemático. Como siguiente paso, se sugiere argumentar las respuestas y pedir aclaración cuando sea necesario.',
    'El trabajo de aula combinó proyectos, lectura y actividades prácticas. Se recomienda continuar con hábitos de estudio constantes y compartir los avances con claridad.',
];
$tenant = Tenant::query()->where('slug', 'inmaculada')->where('name', 'Inmaculada')->firstOrFail();
if ($tenant->tipo === Tenant::TIPO_SEDE) {
    throw new RuntimeException('La carga solo puede ejecutarse en el colegio matriz.');
}

$summary = $tenant->run(function () use ($tenant, $apply, $goals, $preschoolNotes, $primaryNotes): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->where('estado', 'en_curso')
        ->whereNull('deleted_at')->first();
    if (! $year) {
        throw new RuntimeException('El año 2026 no está en curso.');
    }
    $period = DB::table('periodos')->where('ano_lectivo_id', $year->id)->where('orden', 4)->first();
    if (! $period || $period->estado !== 'abierto') {
        throw new RuntimeException('El cuarto período no está abierto.');
    }
    $actor = User::where('email', 'maria@gmail.com')->first();
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->whereNull('g.deleted_at')
        ->whereNull('d.deleted_at')->orderBy('d.id')->orderBy('g.id')
        ->get(['g.id', 'g.grado_id', 'g.nombre as grupo', 'd.nombre as grado']);
    $grades = ['Prejardín', 'Jardín', 'Transición', 'Primero', 'Segundo', 'Tercero', 'Cuarto',
        'Quinto', 'Sexto', 'Séptimo', 'Octavo', 'Noveno', 'Décimo', 'Undécimo'];
    if ($groups->pluck('grado')->unique()->diff($grades)->isNotEmpty()
        || collect($grades)->diff($groups->pluck('grado')->unique())->isNotEmpty()) {
        throw new RuntimeException('La estructura de grados cambió: '.implode(', ', $groups->pluck('grado')->unique()->all()).'; no se modificó nada.');
    }
    $targets = $groups->map(function ($group) use ($year, $goals): array {
        $name = $group->grado;
        $enrollments = DB::table('matriculas as m')->join('users as u', 'u.id', '=', 'm.estudiante_id')
            ->where('m.ano_lectivo_id', $year->id)->where('m.grupo_id', $group->id)
            ->where('m.estado', 'activa')->select('m.id', 'u.email')->get();
        $subjects = DB::table('materias_curriculares as mc')
            ->join('materias as s', 's.id', '=', 'mc.materia_id')
            ->where('mc.ano_lectivo_id', $year->id)->where('mc.grado_id', $group->grado_id)
            ->orderBy('s.nombre')->pluck('s.nombre')->all();
        if (! in_array(count($subjects), [11, 15], true) || count(array_diff($subjects, array_keys($goals))) !== 0
            || $enrollments->contains(fn ($m) => ! str_ends_with($m->email, '.invalid')
                && ! str_ends_with($m->email, '.example'))) {
            throw new RuntimeException("{$name} {$group->grupo}: currículo ".count($subjects).
                ', materias sin plantilla '.implode(', ', array_diff($subjects, array_keys($goals))).
                ', matrículas '.$enrollments->count().', correos no sintéticos '.
                $enrollments->filter(fn ($m) => ! str_ends_with($m->email, '.invalid')
                    && ! str_ends_with($m->email, '.example'))->count().'. No se modificó nada.');
        }

        return ['grado' => $name, 'grupo' => $group->grupo, 'grupo_id' => $group->id,
            'grado_id' => $group->grado_id, 'estudiantes' => $enrollments->count(),
            'correos_example' => $enrollments->filter(fn ($m) => str_ends_with($m->email, '.example'))->count(),
            'correos_invalid' => $enrollments->filter(fn ($m) => str_ends_with($m->email, '.invalid'))->count(),
            'otros_correos' => $enrollments->filter(fn ($m) => ! str_ends_with($m->email, '.invalid')
                && ! str_ends_with($m->email, '.example'))->count(),
            'materias' => $subjects];
    });

    if ($apply) {
        if (! app()->environment('local') || ! in_array(DB::connection()->getConfig('host'),
            ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Esta carga de muestra solo se permite en PostgreSQL local.');
        }
        if (! $actor || $actor->status !== 'active' || ! $actor->hasRole('rector')
            || ! $actor->can('indicadores.base.gestionar')
            || ! $actor->can('boletines.observaciones.gestionar')) {
            throw new RuntimeException('María no tiene los permisos necesarios; no se modificó nada.');
        }
        $config = DB::connection()->getConfig();
        $dumpBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_dump.exe';
        $restoreBinary = 'C:/Program Files/PostgreSQL/16/bin/pg_restore.exe';
        if (! is_file($dumpBinary) || ! is_file($restoreBinary)) {
            throw new RuntimeException('Falta PostgreSQL 16 para respaldar antes de la carga.');
        }
        $backupDir = base_path('storage/app/local-sample-backups');
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0700, true) && ! is_dir($backupDir)) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
        }
        $backupPath = $backupDir.'/inmaculada-indicadores-observaciones-'.date('Ymd-His').'.dump';
        $dump = new Process([$dumpBinary, '--format=custom', '--no-owner', '--host='.(string) $config['host'],
            '--port='.(string) $config['port'], '--username='.(string) $config['username'],
            '--file='.$backupPath, (string) $config['database']], base_path(),
            ['PGPASSWORD' => (string) $config['password']], null, 300);
        $dump->mustRun();
        if (! is_file($backupPath) || filesize($backupPath) < 1024) {
            throw new RuntimeException('El respaldo quedó vacío; no se modificó nada.');
        }
        (new Process([$restoreBinary, '--list', $backupPath], base_path(), null, null, 60))->mustRun();

        $requestFor = static function (string $method, array $data) use ($actor): Request {
            $request = Request::create('/', $method, $data);
            $request->setUserResolver(fn () => $actor);

            return $request;
        };
        $indicatorController = app(IndicadorController::class);
        $observationController = app(BoletinObservacionController::class);
        $counts = DB::transaction(function () use ($year, $period, $targets, $goals,
            $preschoolNotes, $primaryNotes, $requestFor, $indicatorController, $observationController): array {
            $created = ['indicadores_base_nuevos' => 0, 'vinculos_nuevos' => 0,
                'observaciones_borrador_nuevas' => 0];
            foreach ($targets as $target) {
                $subjects = DB::table('materias_curriculares as mc')
                    ->join('materias as s', 's.id', '=', 'mc.materia_id')
                    ->where('mc.ano_lectivo_id', $year->id)->where('mc.grado_id', $target['grado_id'])
                    ->orderBy('s.nombre')->get(['s.id', 's.nombre']);
                foreach ($subjects as $subject) {
                    foreach ($goals[$subject->nombre] as $index => $text) {
                        $base = DB::table('indicadores_base')->where('texto', $text)->first();
                        $binding = $base ? DB::table('indicadores_vinculos')->where('indicador_id', $base->id)
                            ->where('ano_lectivo_id', $year->id)->where('grado_id', $target['grado_id'])
                            ->where('materia_id', $subject->id)->first() : null;
                        if ($binding) {
                            if (json_decode($binding->periodos_orden, true) !== [4]) {
                                throw new RuntimeException('Un indicador existente tiene un alcance distinto; no se sobrescribe.');
                            }
                            continue;
                        }
                        $context = ['ano_token' => OpaqueUrlToken::for('ano-lectivo', $year->id),
                            'grado_token' => OpaqueUrlToken::for('grado', $target['grado_id']),
                            'materia_token' => OpaqueUrlToken::for('materia', $subject->id),
                            'periodos_orden' => [4]];
                        if ($base) {
                            $indicatorController->vincular($requestFor('POST', $context),
                                OpaqueUrlToken::for('indicador-base', $base->id));
                        } else {
                            $indicatorController->crear($requestFor('POST', [
                                ...$context, 'texto' => $text, 'orden' => $index + 1,
                            ]));
                            $created['indicadores_base_nuevos']++;
                        }
                        $created['vinculos_nuevos']++;
                    }
                }
                $enrollments = DB::table('matriculas')->where('ano_lectivo_id', $year->id)
                    ->where('grupo_id', $target['grupo_id'])->where('estado', 'activa')
                    ->orderBy('id')->pluck('id');
                $templates = in_array($target['grado'], ['Prejardín', 'Jardín', 'Transición'], true)
                    ? $preschoolNotes : $primaryNotes;
                foreach ($enrollments as $enrollmentId) {
                    if (DB::table('observaciones_boletin')->where('matricula_id', $enrollmentId)
                        ->where('contexto', 'periodo:'.$period->id)->exists()) {
                        continue;
                    }
                    $content = '[Borrador de muestra] '.$templates[$enrollmentId % count($templates)];
                    $observationController->guardar($requestFor('PUT', [
                        'contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
                        'contenido' => $content, 'version' => 0,
                    ]), OpaqueUrlToken::for('matricula', $enrollmentId));
                    $created['observaciones_borrador_nuevas']++;
                }
            }

            return $created;
        });
    }

    return ['modo' => $apply ? 'aplicado' : 'plan', 'tenant' => $tenant->name,
        'periodo' => $period->nombre, 'grupos' => $targets->all(),
        'actor' => $actor ? ['activo' => $actor->status, 'rector' => $actor->hasRole('rector'),
            'indicadores' => $actor->can('indicadores.base.gestionar'),
            'observaciones' => $actor->can('boletines.observaciones.gestionar')] : null,
        'database' => DB::connection()->getDatabaseName(),
        'indicadores_base' => DB::table('indicadores_base')->count(),
        'indicadores_vinculos' => DB::table('indicadores_vinculos')->count(),
        'observaciones' => DB::table('observaciones_boletin')->count(),
        'observaciones_p4' => DB::table('observaciones_boletin')->where('periodo_id', $period->id)->count(),
        'observaciones_borrador_p4' => DB::table('observaciones_boletin')
            ->where('periodo_id', $period->id)->where('estado', 'borrador')->count(),
        'observaciones_aprobadas_p4' => DB::table('observaciones_boletin')
            ->where('periodo_id', $period->id)->where('estado', 'aprobada')->count(),
        'vinculos_otros_periodos' => DB::table('indicadores_vinculos')->where('ano_lectivo_id', $year->id)
            ->whereRaw('periodos_orden::text <> ?', ['[4]'])->count(),
        'boletines_emitidos' => DB::table('boletin_emisiones')->count(),
        'valoracion_indicadores_retirada' => ! Schema::hasTable('indicadores_valoraciones')
            && ! Schema::hasTable('indicadores_configuracion'),
        'politica_boletines' => DB::table('configuracion_boletines')->first(['requiere_observacion_final']),
        'respaldo' => $backupPath ?? null, 'nuevos' => $counts ?? null,
    ];
});

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
