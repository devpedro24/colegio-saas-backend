<?php

declare(strict_types=1);

/**
 * Carga local, explícita y de una sola vez para el tenant "inmaclada" (Inmaculada).
 * La oferta, las intensidades y el horario son una base escolar verosímil para
 * probar el sistema; no representan información oficial certificada del colegio.
 * No crea alumnos ni docentes. Todas las clases quedan pendientes de docente.
 *
 * Ejecutar: php scripts/cargar_inmaculada_2026.php
 */

use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenant = Tenant::query()->where('slug', 'inmaclada')->firstOrFail();
if ($tenant->name !== 'Inmaculada' || $tenant->tipo === Tenant::TIPO_SEDE) {
    throw new RuntimeException('El tenant inmaclada no corresponde al colegio esperado.');
}

$result = $tenant->run(function (): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->whereNull('deleted_at')->first();
    if (! $year || DB::table('anos_lectivos')->whereNull('deleted_at')->count() !== 1
        || $year->estado !== 'planificado' || $year->tipo_calendario !== 'A') {
        throw new RuntimeException('Se esperaba un único año 2026 de Calendario A en estado planificado. No se modificó nada.');
    }

    $existing = [];
    foreach ([
        'sedes', 'jornadas', 'niveles', 'grados', 'grupos', 'bloques_horarios',
        'espacios_fisicos', 'areas', 'materias', 'materias_curriculares',
        'escalas_valorativas', 'metodos_aprobacion', 'modelos_pedagogicos',
        'asignaciones_docentes', 'sesiones_horario', 'matriculas', 'calificaciones',
    ] as $table) {
        $existing[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
    if (array_sum($existing) !== 0) {
        throw new RuntimeException('Ya hay información académica o dependiente en Inmaculada. Se detuvo la carga para conservarla: '.json_encode($existing));
    }

    $periods = DB::table('periodos')->where('ano_lectivo_id', $year->id)->whereNull('deleted_at')->orderBy('orden')->get();
    if ($periods->count() !== 4 || $periods->pluck('orden')->all() !== [1, 2, 3, 4]
        || $periods->contains(fn ($period) => $period->estado !== 'planificado')) {
        throw new RuntimeException('Los cuatro períodos originales no están íntegros y planificados. No se modificó nada.');
    }

    $backupDir = base_path('storage/app/private');
    if (! is_dir($backupDir) && ! mkdir($backupDir, 0700, true) && ! is_dir($backupDir)) {
        throw new RuntimeException('No se pudo preparar el directorio del respaldo. No se modificó nada.');
    }
    $backupPath = $backupDir.'/inmaclada-2026-before-'.date('Ymd-His').'.json';
    $backup = json_encode(['year' => $year, 'periods' => $periods], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($backupPath, $backup) !== strlen($backup)) {
        throw new RuntimeException('No se pudo guardar el respaldo previo en '.$backupPath.'. No se modificó nada.');
    }

    $summary = DB::transaction(function () use ($year, $periods): array {
        $stamp = now();
        $insert = static function (string $table, array $data) use ($stamp): int {
            return DB::table($table)->insertGetId([...$data, 'created_at' => $stamp, 'updated_at' => $stamp]);
        };
        $yearId = (int) $year->id;

        $today = now()->toDateString();
        $yearInProgress = $today >= '2026-01-19' && $today <= '2026-11-27';
        DB::table('anos_lectivos')->where('id', $yearId)->update([
            'fecha_inicio' => '2026-01-19', 'fecha_fin' => '2026-11-27',
            'num_periodos' => 4, 'periodo_sumatorio' => true,
            'estado' => $yearInProgress ? 'en_curso' : 'planificado', 'updated_at' => $stamp,
        ]);
        $periodSpecs = [
            ['Primer período', '2026-01-19', '2026-04-03'],
            ['Segundo período', '2026-04-04', '2026-06-19'],
            ['Tercer período', '2026-06-20', '2026-09-04'],
            ['Cuarto período', '2026-09-05', '2026-11-27'],
        ];
        $currentPeriod = null;
        foreach ($periods as $index => $period) {
            [$name, $start, $end] = $periodSpecs[$index];
            $isCurrent = $yearInProgress && $today >= $start && $today <= $end;
            if ($isCurrent) {
                $currentPeriod = $name;
            }
            DB::table('periodos')->where('id', $period->id)->update([
                'nombre' => $name, 'fecha_inicio' => $start, 'fecha_fin' => $end,
                'peso' => 25, 'estado' => $isCurrent ? 'abierto' : 'planificado', 'updated_at' => $stamp,
            ]);
        }

        $institution = DB::table('datos_institucionales')->first();
        $campusId = $insert('sedes', [
            'nombre' => 'Sede Principal',
            'direccion' => $institution?->direccion,
            'telefono' => $institution?->telefono,
            'estado' => 'activa',
            'tenant_id' => null,
        ]);

        $shifts = [
            'manana' => ['Mañana', '07:00:00', '12:30:00', [
                ['Primera hora', '07:00:00', '07:50:00', false],
                ['Segunda hora', '07:50:00', '08:40:00', false],
                ['Tercera hora', '08:40:00', '09:30:00', false],
                ['Descanso', '09:30:00', '10:00:00', true],
                ['Cuarta hora', '10:00:00', '10:50:00', false],
                ['Quinta hora', '10:50:00', '11:40:00', false],
                ['Sexta hora', '11:40:00', '12:30:00', false],
            ]],
            'tarde' => ['Tarde', '12:40:00', '18:10:00', [
                ['Primera hora', '12:40:00', '13:30:00', false],
                ['Segunda hora', '13:30:00', '14:20:00', false],
                ['Tercera hora', '14:20:00', '15:10:00', false],
                ['Descanso', '15:10:00', '15:40:00', true],
                ['Cuarta hora', '15:40:00', '16:30:00', false],
                ['Quinta hora', '16:30:00', '17:20:00', false],
                ['Sexta hora', '17:20:00', '18:10:00', false],
            ]],
        ];
        $shiftIds = [];
        $blockIds = [];
        foreach ($shifts as $key => [$name, $start, $end, $blocks]) {
            $shiftIds[$key] = $insert('jornadas', [
                'ano_lectivo_id' => $yearId, 'sede_id' => $campusId,
                'nombre' => $name, 'hora_inicio' => $start, 'hora_fin' => $end, 'estado' => 'activa',
            ]);
            foreach ($blocks as [$blockName, $blockStart, $blockEnd, $isBreak]) {
                $blockId = $insert('bloques_horarios', [
                    'ano_lectivo_id' => $yearId, 'jornada_id' => $shiftIds[$key],
                    'nombre' => $blockName, 'hora_inicio' => $blockStart,
                    'hora_fin' => $blockEnd, 'es_descanso' => $isBreak, 'estado' => 'activo',
                ]);
                if (! $isBreak) {
                    $blockIds[$key][] = $blockId;
                }
            }
        }

        $levelSpecs = [
            'preescolar' => ['Preescolar', [['Prejardín', 'PJ'], ['Jardín', 'JA'], ['Transición', 'TR']]],
            'primaria' => ['Básica Primaria', [['Primero', '01'], ['Segundo', '02'], ['Tercero', '03'], ['Cuarto', '04'], ['Quinto', '05']]],
            'secundaria' => ['Básica Secundaria', [['Sexto', '06'], ['Séptimo', '07'], ['Octavo', '08'], ['Noveno', '09']]],
            'media' => ['Educación Media', [['Décimo', '10'], ['Undécimo', '11']]],
        ];
        $levelIds = [];
        $gradeIds = [];
        $gradeLevel = [];
        foreach ($levelSpecs as $key => [$name, $grades]) {
            $levelIds[$key] = $insert('niveles', [
                'ano_lectivo_id' => $yearId, 'nivel_educativo' => $key,
                'nombre' => $name, 'estado' => 'activo',
            ]);
            foreach ($grades as [$gradeName, $code]) {
                $gradeId = $insert('grados', [
                    'ano_lectivo_id' => $yearId, 'nivel_id' => $levelIds[$key],
                    'nombre' => $gradeName, 'codigo' => $code, 'estado' => 'activo',
                ]);
                $gradeIds[$code] = $gradeId;
                $gradeLevel[$gradeId] = $key;
            }
        }

        $roomIds = [];
        foreach (array_keys($gradeIds) as $code) {
            $roomIds[$code] = $insert('espacios_fisicos', [
                'ano_lectivo_id' => $yearId, 'sede_id' => $campusId,
                'nombre' => 'Aula '.$code, 'tipo' => 'aula',
                'capacidad' => in_array($code, ['PJ', 'JA', 'TR'], true) ? 25 : 32,
                'ubicacion' => in_array($code, ['PJ', 'JA', 'TR'], true) ? 'Bloque Infantil' : 'Bloque Académico',
                'estado' => 'disponible',
            ]);
        }
        foreach ([
            ['Laboratorio de Ciencias', 'laboratorio', 30, 'Bloque Académico'],
            ['Sala de Informática', 'laboratorio', 30, 'Bloque Académico'],
            ['Biblioteca', 'biblioteca', 45, 'Bloque Administrativo'],
            ['Aula de Arte', 'aula', 30, 'Bloque Académico'],
            ['Auditorio', 'auditorio', 120, 'Bloque Administrativo'],
            ['Patio Central', 'patio', 150, 'Zona Común'],
        ] as [$spaceName, $type, $capacity, $location]) {
            $insert('espacios_fisicos', [
                'ano_lectivo_id' => $yearId, 'sede_id' => $campusId,
                'nombre' => $spaceName, 'tipo' => $type, 'capacidad' => $capacity,
                'ubicacion' => $location, 'estado' => 'disponible',
            ]);
        }

        $groups = [];
        foreach ($gradeIds as $code => $gradeId) {
            $level = $gradeLevel[$gradeId];
            $isPreschool = $level === 'preescolar';
            $groups[] = [
                'id' => $insert('grupos', [
                    'ano_lectivo_id' => $yearId, 'grado_id' => $gradeId,
                    'jornada_id' => $shiftIds['manana'], 'sede_id' => $campusId,
                    'nombre' => $code.'A', 'cupo_maximo' => $isPreschool ? 25 : 30,
                    'estado' => 'activo',
                ]),
                'level' => $level, 'shift' => 'manana', 'room_id' => $roomIds[$code],
            ];
            if (! $isPreschool && (int) $code <= 9) {
                $groups[] = [
                    'id' => $insert('grupos', [
                        'ano_lectivo_id' => $yearId, 'grado_id' => $gradeId,
                        'jornada_id' => $shiftIds['tarde'], 'sede_id' => $campusId,
                        'nombre' => $code.'B', 'cupo_maximo' => 30, 'estado' => 'activo',
                    ]),
                    'level' => $level, 'shift' => 'tarde', 'room_id' => $roomIds[$code],
                ];
            }
        }

        $areaNames = [
            'mat' => 'Matemáticas', 'len' => 'Humanidades y Lengua Castellana',
            'ing' => 'Idiomas Extranjeros', 'nat' => 'Ciencias Naturales y Educación Ambiental',
            'soc' => 'Ciencias Sociales', 'fis' => 'Educación Física, Recreación y Deportes',
            'art' => 'Educación Artística', 'tec' => 'Tecnología e Informática',
            'eti' => 'Ética y Valores Humanos', 'rel' => 'Educación Religiosa',
            'fil' => 'Filosofía', 'eco' => 'Ciencias Económicas y Políticas',
        ];
        $areaIds = [];
        foreach ($areaNames as $code => $name) {
            $areaIds[$code] = $insert('areas', [
                'ano_lectivo_id' => $yearId, 'nombre' => $name,
                'descripcion' => null, 'estado' => 'activo',
            ]);
        }

        // Código => [nombre, área, nivel específico o null, horas semanales].
        $subjects = [
            'PRE-COM' => ['Dimensión Comunicativa', 'len', 'preescolar', 6],
            'PRE-COG' => ['Dimensión Cognitiva', 'mat', 'preescolar', 6],
            'PRE-MED' => ['Exploración del Medio', 'nat', 'preescolar', 5],
            'PRE-COR' => ['Dimensión Corporal', 'fis', 'preescolar', 5],
            'PRE-EST' => ['Expresión Artística', 'art', 'preescolar', 4],
            'PRE-SOC' => ['Convivencia y Autonomía', 'eti', 'preescolar', 4],
            'PRI-MAT' => ['Matemáticas', 'mat', 'primaria', 5],
            'PRI-LEN' => ['Lengua Castellana', 'len', 'primaria', 5],
            'PRI-ING' => ['Inglés Básico', 'ing', 'primaria', 3],
            'PRI-NAT' => ['Ciencias Naturales', 'nat', 'primaria', 3],
            'PRI-SOC' => ['Ciencias Sociales', 'soc', 'primaria', 3],
            'COM-FIS' => ['Educación Física', 'fis', null, 2],
            'COM-ART' => ['Educación Artística', 'art', null, 2],
            'COM-TEC' => ['Tecnología e Informática', 'tec', null, 2],
            'COM-ETI' => ['Ética y Valores', 'eti', null, 1],
            'COM-REL' => ['Educación Religiosa', 'rel', null, 1],
            'PRI-LEC' => ['Taller de Lectura y Escritura', 'len', 'primaria', 3],
            'SEC-MAT' => ['Álgebra y Geometría', 'mat', 'secundaria', 5],
            'SEC-LEN' => ['Lengua y Literatura', 'len', 'secundaria', 4],
            'SEC-ING' => ['Inglés Intermedio', 'ing', 'secundaria', 3],
            'SEC-NAT' => ['Biología y Ambiente', 'nat', 'secundaria', 4],
            'SEC-SOC' => ['Historia y Geografía', 'soc', 'secundaria', 3],
            'SEC-PRO' => ['Proyecto Integrador', 'tec', 'secundaria', 3],
            'MED-MAT' => ['Matemáticas Aplicadas', 'mat', 'media', 5],
            'MED-LEN' => ['Literatura y Comunicación', 'len', 'media', 4],
            'MED-ING' => ['Inglés Avanzado', 'ing', 'media', 3],
            'MED-FIS' => ['Física', 'nat', 'media', 3],
            'MED-QUI' => ['Química', 'nat', 'media', 3],
            'MED-BIO' => ['Biología', 'nat', 'media', 2],
            'MED-FIL' => ['Filosofía', 'fil', 'media', 2],
            'MED-ECO' => ['Ciencias Económicas y Políticas', 'eco', 'media', 2],
            'MED-AFI' => ['Actividad Física', 'fis', 'media', 1],
            'MED-INV' => ['Investigación Escolar', 'tec', 'media', 1],
        ];
        $curriculum = [
            'preescolar' => ['PRE-COM', 'PRE-COG', 'PRE-MED', 'PRE-COR', 'PRE-EST', 'PRE-SOC'],
            'primaria' => ['PRI-MAT', 'PRI-LEN', 'PRI-ING', 'PRI-NAT', 'PRI-SOC', 'COM-FIS', 'COM-ART', 'COM-TEC', 'COM-ETI', 'COM-REL', 'PRI-LEC'],
            'secundaria' => ['SEC-MAT', 'SEC-LEN', 'SEC-ING', 'SEC-NAT', 'SEC-SOC', 'COM-FIS', 'COM-ART', 'COM-TEC', 'COM-ETI', 'COM-REL', 'SEC-PRO'],
            'media' => ['MED-MAT', 'MED-LEN', 'MED-ING', 'MED-FIS', 'MED-QUI', 'MED-BIO', 'MED-FIL', 'MED-ECO', 'MED-AFI', 'COM-TEC', 'COM-ETI', 'COM-REL', 'MED-INV'],
        ];
        $subjectIds = [];
        foreach ($subjects as $code => [$name, $area, $level, $hours]) {
            $subjectIds[$code] = $insert('materias', [
                'ano_lectivo_id' => $yearId, 'area_id' => $areaIds[$area],
                'nivel_id' => $level ? $levelIds[$level] : null,
                'nombre' => $name, 'codigo' => $code,
                'intensidad_horaria' => $hours, 'estado' => 'activo',
            ]);
        }
        foreach ($gradeLevel as $gradeId => $level) {
            foreach ($curriculum[$level] as $code) {
                $insert('materias_curriculares', [
                    'ano_lectivo_id' => $yearId, 'grado_id' => $gradeId,
                    'materia_id' => $subjectIds[$code],
                    'area_id' => $areaIds[$subjects[$code][1]],
                    'peso_area' => null,
                ]);
            }
        }

        $scaleId = $insert('escalas_valorativas', [
            'ano_lectivo_id' => $yearId, 'nivel_educativo' => null,
            'nombre' => 'Escala institucional de 0 a 5', 'tipo' => 'numerica',
            'valor_min' => 0, 'valor_max' => 5, 'decimales' => 2,
        ]);
        $methodId = $insert('metodos_aprobacion', [
            'ano_lectivo_id' => $yearId, 'calculo_nota' => 'promedio_simple',
            'nota_minima' => 3, 'ambito' => 'materia',
        ]);
        foreach (array_keys($levelSpecs) as $level) {
            $singleTeacher = in_array($level, ['preescolar', 'primaria'], true);
            $insert('modelos_pedagogicos', [
                'ano_lectivo_id' => $yearId, 'nivel_educativo' => $level,
                'docente_unico' => $singleTeacher,
                'salon_fijo' => true, 'tiene_director_grupo' => true,
            ]);
        }
        DB::table('anos_lectivos')->where('id', $yearId)->update([
            'siee' => json_encode([
                'usar_areas' => true, 'modo_area' => 'SIMPLE_AVERAGE',
                'modo_asignatura' => 'SIMPLE_AVERAGE', 'modo_anual' => 'WEIGHTED_AVERAGE',
                'redondeo' => 'HALF_UP', 'precision_calculo' => 8,
                'recuperacion' => 'REPLACE', 'mostrar_final' => true,
                'etiqueta_final' => 'Definitiva anual',
                'escala_id' => $scaleId, 'metodo_id' => $methodId,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => $stamp,
        ]);

        // Horario semanal completo. Cada grado tiene aula propia que comparten
        // A (mañana) y B (tarde) sin choque; no se inventa un docente.
        $weekdays = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes'];
        $assignmentCount = 0;
        $sessionCount = 0;
        foreach ($groups as $group) {
            $codes = $curriculum[$group['level']];
            $assignmentIds = [];
            $remaining = [];
            foreach ($codes as $code) {
                $assignmentIds[$code] = $insert('asignaciones_docentes', [
                    'ano_lectivo_id' => $yearId, 'grupo_id' => $group['id'],
                    'materia_id' => $subjectIds[$code], 'docente_id' => null,
                ]);
                $remaining[$code] = $subjects[$code][3];
                $assignmentCount++;
            }
            $order = [];
            while (array_sum($remaining) > 0) {
                foreach ($codes as $code) {
                    if ($remaining[$code] > 0) {
                        $order[] = $code;
                        $remaining[$code]--;
                    }
                }
            }
            if (count($order) !== 30) {
                throw new RuntimeException('El currículo de '.$group['level'].' no suma 30 horas semanales.');
            }
            foreach ($weekdays as $dayIndex => $day) {
                foreach ($blockIds[$group['shift']] as $blockIndex => $blockId) {
                    $code = $order[$dayIndex * 6 + $blockIndex];
                    $insert('sesiones_horario', [
                        'ano_lectivo_id' => $yearId,
                        'asignacion_id' => $assignmentIds[$code],
                        'grupo_id' => $group['id'],
                        'materia_id' => $subjectIds[$code],
                        'docente_id' => null,
                        'dia' => $day, 'bloque_horario_id' => $blockId,
                        'hora_inicio' => null, 'hora_fin' => null,
                        'espacio_fisico_id' => $group['room_id'],
                    ]);
                    $sessionCount++;
                }
            }
        }

        return [
            'ano_lectivo' => $yearId,
            'estado_ano' => $yearInProgress ? 'en_curso' : 'planificado',
            'periodo_abierto' => $currentPeriod,
            'periodos' => count($periodSpecs),
            'sedes' => 1, 'jornadas' => count($shiftIds),
            'niveles' => count($levelIds), 'grados' => count($gradeIds),
            'grupos' => count($groups), 'bloques_horarios' => array_sum(array_map('count', array_column($shifts, 3))),
            'espacios_fisicos' => count($roomIds) + 6,
            'areas' => count($areaIds), 'materias' => count($subjectIds),
            'materias_curriculares' => DB::table('materias_curriculares')->where('ano_lectivo_id', $yearId)->count(),
            'asignaciones_docentes_pendientes' => $assignmentCount,
            'sesiones_horario' => $sessionCount,
        ];
    });

    AuditLogger::tenant(
        null, 'UPDATE', 'ano_lectivo', (string) $year->id,
        ['fecha_inicio' => $year->fecha_inicio, 'fecha_fin' => $year->fecha_fin, 'num_periodos' => $year->num_periodos, 'estado' => $year->estado],
        ['fecha_inicio' => '2026-01-19', 'fecha_fin' => '2026-11-27', 'num_periodos' => 4, 'estado' => $summary['estado_ano']],
        'Corrección local de calendario y períodos 2026 solicitada para Inmaculada.',
    );
    AuditLogger::tenant(
        null, 'CREATE', 'estructura_academica_2026', (string) $year->id,
        null, $summary,
        'Carga inicial de una base académica escolar verosímil. Docentes pendientes; no son datos oficiales certificados.',
    );

    return ['backup' => $backupPath, 'summary' => $summary];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
