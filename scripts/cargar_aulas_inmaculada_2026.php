<?php

declare(strict_types=1);

/**
 * Contenido demostrativo, nunca calificable ni visible a estudiantes, para las
 * asignaturas curriculares de los siete grupos con matrícula de Inmaculada 2026.
 * Lectura previa: php scripts/cargar_aulas_inmaculada_2026.php
 * Aplicación:     php scripts/cargar_aulas_inmaculada_2026.php --apply
 */

use App\Models\Academico\Aula;
use App\Models\Academico\AulaPregunta;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$tenant = Tenant::query()->where('slug', 'inmaculada')->where('name', 'Inmaculada')->firstOrFail();
if ($tenant->tipo === Tenant::TIPO_SEDE) throw new RuntimeException('La carga solo corresponde al colegio matriz.');

$topics = [
    'Matemáticas' => ['Patrones y problemas cotidianos', 'Busca una regla en la secuencia 2, 4, 6, 8 y explica cómo la descubriste.', 'Inventa una situación de suma o resta con objetos de tu entorno.'],
    'Lengua Castellana' => ['Leer para comprender', 'Lee un relato breve e identifica personaje, lugar e idea principal.', 'Escribe tres oraciones que cuenten el inicio, el desarrollo y el final.'],
    'Taller de Lectura y Escritura' => ['Historias que podemos contar', 'Escoge un texto corto y subraya las palabras que no conoces.', 'Redacta un párrafo nuevo con un título y una idea principal.'],
    'Ciencias Naturales' => ['Seres vivos y su entorno', 'Observa una planta cercana y describe qué necesita para vivir.', 'Compara dos seres vivos y registra una semejanza y una diferencia.'],
    'Ciencias Sociales' => ['Mi comunidad y sus espacios', 'Reconoce los lugares importantes de tu barrio o vereda.', 'Dibuja un recorrido seguro y explica cómo cuidamos los espacios comunes.'],
    'Inglés Básico' => ['Greetings and everyday words', 'Practica saludos y cinco palabras de objetos del salón.', 'Escribe o di dos frases sencillas usando el vocabulario aprendido.'],
    'Educación Física' => ['Movimiento y bienestar', 'Reconoce la importancia del calentamiento antes de jugar.', 'Propón un circuito corto de equilibrio, coordinación y descanso.'],
    'Educación Artística' => ['Colores, formas y creatividad', 'Explora colores primarios y formas en objetos cercanos.', 'Crea una composición y cuenta qué quisiste expresar.'],
    'Expresión Artística' => ['Jugamos con formas y colores', 'Observa colores y texturas en el aula o en casa.', 'Crea una figura con materiales disponibles y descríbela.'],
    'Educación Religiosa' => ['Respeto y cuidado de los demás', 'Conversa sobre una acción de solidaridad en tu comunidad.', 'Describe cómo puedes ayudar a otra persona esta semana.'],
    'Ética y Valores' => ['Acuerdos para convivir', 'Identifica cómo escuchar, esperar el turno y resolver un desacuerdo.', 'Propón dos acuerdos para cuidar a tus compañeros.'],
    'Tecnología e Informática' => ['Secuencias y uso responsable', 'Ordena los pasos para resolver una tarea sencilla.', 'Explica una regla para usar los dispositivos de forma segura.'],
    'Convivencia y Autonomía' => ['Nos cuidamos en grupo', 'Reconoce emociones y escucha a tus compañeros.', 'Practica guardar materiales y respetar turnos de juego.'],
    'Dimensión Cognitiva' => ['Formas, colores y patrones', 'Agrupa objetos por color o forma y completa un patrón sencillo.', 'Construye una secuencia con tres objetos y explica qué sigue.'],
    'Dimensión Comunicativa' => ['Escucho y cuento historias', 'Escucha un cuento corto e identifica a sus personajes.', 'Cuenta la parte que más te gustó usando tus propias palabras.'],
    'Dimensión Corporal' => ['Conozco mi cuerpo en movimiento', 'Explora movimientos suaves de brazos, piernas y equilibrio.', 'Realiza un juego de coordinación respetando tu espacio y el de otros.'],
    'Exploración del Medio' => ['Descubrimos nuestro entorno', 'Observa una planta, una piedra o una nube y describe sus características.', 'Comparte una pregunta sobre lo que viste y busca una respuesta con ayuda.'],
];

$result = $tenant->run(function () use ($apply, $topics): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->whereNull('deleted_at')->first();
    if (! $year) throw new RuntimeException('No existe el año lectivo 2026.');
    $period = DB::table('periodos')->where('ano_lectivo_id', $year->id)->where('orden', 4)->first();
    if (! $period || $period->estado !== 'abierto') throw new RuntimeException('El cuarto período debe estar abierto. No se modificó nada.');

    $rows = DB::table('grupos as g')
        ->join('grados as gr', 'gr.id', '=', 'g.grado_id')
        ->join('materias_curriculares as mc', fn ($join) => $join->on('mc.grado_id', '=', 'g.grado_id')
            ->on('mc.ano_lectivo_id', '=', 'g.ano_lectivo_id'))
        ->join('materias as m', 'm.id', '=', 'mc.materia_id')
        ->where('g.ano_lectivo_id', $year->id)->whereNull('g.deleted_at')->whereNull('m.deleted_at')
        ->whereExists(fn ($query) => $query->selectRaw('1')->from('matriculas as ma')
            ->whereColumn('ma.grupo_id', 'g.id')->where('ma.ano_lectivo_id', $year->id)->where('ma.estado', 'activa'))
        ->orderBy('g.id')->orderBy('m.nombre')
        ->get(['g.id as grupo_id', 'g.nombre as grupo', 'gr.nombre as grado', 'm.id as materia_id', 'm.nombre as materia']);
    $counts = ['grupos' => $rows->unique('grupo_id')->count(), 'combinaciones_curriculares' => $rows->count(),
        'aulas_nuevas' => 0, 'secciones_nuevas' => 0, 'recursos_nuevos' => 0, 'preguntas_nuevas' => 0];
    if ($counts['grupos'] !== 7 || $counts['combinaciones_curriculares'] !== 77) {
        throw new RuntimeException('El alcance cambió; revisa los grupos y el currículo antes de cargar: '.json_encode($counts));
    }
    if (! $apply) return ['modo' => 'vista_previa', ...$counts];

    $backupDir = storage_path('app/private');
    if (! is_dir($backupDir) && ! mkdir($backupDir, 0700, true) && ! is_dir($backupDir)) {
        throw new RuntimeException('No se pudo preparar la carpeta privada de respaldo.');
    }
    $backupPath = $backupDir.'/inmaculada-aula-before-'.date('Ymd-His').'.json';
    $snapshot = [];
    foreach (['aulas', 'aula_secciones', 'aula_recursos', 'aula_preguntas'] as $table) {
        $snapshot[$table] = DB::table($table)->get()->all();
    }
    $backup = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($backupPath, $backup) !== strlen($backup)) {
        throw new RuntimeException('No se pudo guardar el respaldo previo. No se modificó nada.');
    }

    DB::transaction(function () use ($rows, $period, $year, $topics, &$counts): void {
        foreach ($rows as $row) {
            [$topic, $idea, $practice] = $topics[$row->materia] ?? [
                'Exploramos '.$row->materia, 'Observa un ejemplo de '.$row->materia.' y formula una pregunta.',
                'Explica con tus palabras qué aprendiste y comparte un ejemplo.',
            ];
            $preschool = in_array($row->grado, ['Prejardín', 'Jardín', 'Transición'], true);
            $aula = Aula::firstOrCreate(['grupo_id' => $row->grupo_id, 'materia_id' => $row->materia_id],
                ['ano_lectivo_id' => $year->id]);
            if ($aula->wasRecentlyCreated) $counts['aulas_nuevas']++;
            $intro = AulaSeccion::firstOrCreate(['aula_id' => $aula->id, 'periodo_id' => $period->id,
                'titulo' => '[Muestra] Punto de partida'], ['orden' => 1, 'visible_estudiantes' => false]);
            $practiceSection = AulaSeccion::firstOrCreate(['aula_id' => $aula->id, 'periodo_id' => $period->id,
                'titulo' => '[Muestra] Practicamos y comprobamos'], ['orden' => 2, 'visible_estudiantes' => false]);
            $counts['secciones_nuevas'] += (int) $intro->wasRecentlyCreated + (int) $practiceSection->wasRecentlyCreated;
            $guide = AulaRecurso::firstOrCreate(['seccion_id' => $intro->id, 'titulo' => '[Muestra] Guía: '.$topic], [
                'tipo' => 'texto', 'orden' => 1, 'estado' => 'borrador', 'visible_estudiantes' => false,
                'calificable' => false, 'llevar_planilla' => false, 'version' => 1,
                'configuracion' => ['origen_carga' => 'inmaculada_aula_2026_demo_v1'],
                'contenido' => ['bloques' => [
                    ['tipo' => 'titulo', 'texto' => $topic],
                    ['tipo' => 'aviso', 'texto' => 'Material de demostración para '.$row->grado.' / '.$row->grupo.'. El docente puede editarlo o reemplazarlo antes de publicarlo.'],
                    ['tipo' => 'parrafo', 'texto' => $idea],
                    ['tipo' => 'lista', 'texto' => "Observa y pregunta.\nComparte tus ideas.\nRegistra un ejemplo propio."],
                ]],
            ]);
            $task = AulaRecurso::firstOrCreate(['seccion_id' => $practiceSection->id, 'titulo' => '[Muestra] Actividad: '.$topic], [
                'tipo' => 'tarea', 'orden' => 1, 'estado' => 'borrador', 'visible_estudiantes' => false,
                'calificable' => false, 'llevar_planilla' => false, 'version' => 1,
                'configuracion' => ['origen_carga' => 'inmaculada_aula_2026_demo_v1', 'entrega_tardia' => false, 'reenvios' => true],
                'contenido' => ['bloques' => [
                    ['tipo' => 'titulo', 'texto' => 'Actividad: '.$topic],
                    ['tipo' => 'parrafo', 'texto' => $practice],
                    ['tipo' => 'lista', 'texto' => $preschool
                        ? "Realiza la exploración con acompañamiento.\nCuenta o dibuja lo que observaste.\nComparte tu trabajo con el docente."
                        : "Lee la guía y realiza la actividad.\nExplica cómo llegaste al resultado.\nEntrega una foto o un texto con tu reflexión."],
                    ['tipo' => 'aviso', 'texto' => 'Ejemplo sin fecha ni calificación. Configura plazo, visibilidad y vínculo con la planilla antes de usarlo.'],
                ]],
            ]);
            $counts['recursos_nuevos'] += (int) $guide->wasRecentlyCreated + (int) $task->wasRecentlyCreated;

            if (($preschool && $row->materia === 'Dimensión Cognitiva') || (! $preschool && $row->materia === 'Matemáticas')) {
                $quiz = AulaRecurso::firstOrCreate(['seccion_id' => $practiceSection->id,
                    'titulo' => '[Muestra] Autoevaluación: '.$topic], [
                    'tipo' => 'cuestionario', 'orden' => 2, 'estado' => 'borrador', 'visible_estudiantes' => false,
                    'calificable' => false, 'llevar_planilla' => false, 'version' => 1,
                    'configuracion' => ['origen_carga' => 'inmaculada_aula_2026_demo_v1', 'intentos' => 1,
                        'duracion_minutos' => 15, 'preguntas_por_pagina' => 2, 'permitir_regresar' => true,
                        'permitir_editar_respuestas' => true, 'vigilado' => false],
                    'contenido' => ['bloques' => [['tipo' => 'aviso', 'texto' => 'Cuestionario de ejemplo. Revisa las preguntas antes de publicarlo.']]],
                ]);
                if ($quiz->wasRecentlyCreated) {
                    $counts['recursos_nuevos']++;
                    $questions = $preschool ? [
                        ['¿Cuál figura tiene tres lados?', ['Triángulo', 'Círculo', 'Cuadrado'], 'Triángulo'],
                        ['En la secuencia rojo, azul, rojo, azul, ¿qué color sigue?', ['Rojo', 'Verde', 'Azul'], 'Rojo'],
                    ] : [
                        ['Si tienes tres semillas y agregas dos, ¿cuántas tienes?', ['Cuatro', 'Cinco', 'Seis'], 'Cinco'],
                        ['¿Qué número sigue en 2, 4, 6, 8?', ['Nueve', 'Diez', 'Doce'], 'Diez'],
                    ];
                    foreach ($questions as $order => [$text, $options, $answer]) {
                        AulaPregunta::create(['recurso_id' => $quiz->id, 'tipo' => 'unica', 'enunciado' => $text,
                            'opciones' => $options, 'respuesta_correcta' => ['valor' => $answer], 'puntos' => 1, 'orden' => $order]);
                        $counts['preguntas_nuevas']++;
                    }
                }
            }
        }
        AuditLogger::tenant(null, 'CREATE', 'aula_demo_2026', (string) $year->id, null, $counts,
            'Carga demostrativa solicitada; oculta a estudiantes y sin notas, entregas ni asistencias.');
    });

    return ['modo' => 'aplicado', 'respaldo' => $backupPath, ...$counts];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
