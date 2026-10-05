<?php

declare(strict_types=1);

/**
 * Carga local y sintética para auditar un grupo por grado (Prejardín a Cuarto).
 * Las fechas de las actividades pertenecen al preinforme; los timestamps de
 * creación y la auditoría son reales. Conserva cada nota existente.
 *
 * php scripts/completar_auditoria_inmaculada_2026.php [--apply]
 */

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
if ($tenant->tipo !== Tenant::TIPO_COLEGIO) {
    throw new RuntimeException('El tenant de destino no es un colegio.');
}

$result = $tenant->run(function () use ($apply): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->where('estado', 'en_curso')->whereNull('deleted_at')->first();
    if (! $year) {
        throw new RuntimeException('Falta el año lectivo 2026 en curso.');
    }
    $gradeNames = ['Prejardín', 'Jardín', 'Transición', 'Primero', 'Segundo', 'Tercero', 'Cuarto'];
    $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)->whereIn('d.nombre', $gradeNames)
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')->orderBy('g.id')
        ->get(['g.id', 'g.nombre', 'g.grado_id', 'g.cupo_maximo', 'd.nombre as grado']);
    $selected = collect($gradeNames)->map(function (string $name) use ($groups, $year) {
        $group = $groups->firstWhere('grado', $name);
        if (! $group) {
            throw new RuntimeException("Falta el primer grupo de {$name}.");
        }
        $enrolled = Matricula::where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)
            ->where('estado', 'activa')->count();
        if ($enrolled !== (int) $group->cupo_maximo) {
            throw new RuntimeException("{$name}: {$enrolled} matrículas; el cupo esperado es {$group->cupo_maximo}.");
        }
        $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)
            ->where('grado_id', $group->grado_id)->pluck('materia_id')->all();
        if (count($curriculum) !== 11) {
            throw new RuntimeException("{$name}: el currículo debe contener las 11 materias previstas.");
        }
        $group->materias = $curriculum;

        return $group;
    });
    $periods = Periodo::where('ano_lectivo_id', $year->id)->orderBy('orden')->get();
    if ($periods->count() !== 4 || $periods->pluck('orden')->all() !== [1, 2, 3, 4]) {
        throw new RuntimeException('Se esperaban cuatro períodos de 2026.');
    }
    foreach ($periods as $period) {
        if (! ($period->configuracion_notas['usar_preinformes'] ?? false)) {
            throw new RuntimeException("Faltan preinformes en {$period->nombre}.");
        }
        $pre = Preinforme::where('periodo_id', $period->id)->orderBy('orden')->get();
        if ($pre->count() !== 4 || $pre->pluck('orden')->all() !== [1, 2, 3, 4]
            || $pre->sum(fn ($row) => (int) $row->peso) !== 100) {
            throw new RuntimeException("Los preinformes de {$period->nombre} no son cuatro o no suman 100 %.");
        }
    }
    $scale = EscalaValorativa::with('opciones')->where('ano_lectivo_id', $year->id)
        ->where('nivel_educativo', 'preescolar')->first();
    if (! $scale || $scale->tipo !== 'imagenes' || $scale->opciones->count() !== 4) {
        throw new RuntimeException('Falta la escala visual de cuatro categorías para preescolar.');
    }
    $summary = $selected->map(function ($group) use ($year, $periods) {
        $assignments = AsignacionDocente::where('ano_lectivo_id', $year->id)
            ->where('grupo_id', $group->id)->get();
        $existing = DB::table('calificaciones as n')
            ->join('actividades_evaluacion as a', 'a.id', '=', 'n.actividad_id')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
            ->whereIn('c.asignacion_id', $assignments->modelKeys())
            ->whereIn('c.periodo_id', $periods->modelKeys())->count();
        return ['grado' => $group->grado, 'grupo' => $group->nombre,
            'estudiantes' => (int) $group->cupo_maximo, 'asignaciones' => $assignments->count(),
            'asignaciones_faltantes' => count(array_diff($group->materias, $assignments->pluck('materia_id')->all())),
            'notas_previas_a_conservar' => $existing];
    })->all();
    if (! $apply) {
        return ['modo' => 'vista_previa', 'grupos' => $summary,
            'nota' => 'Con --apply se cargan docentes, actividades y notas; se preservan las notas previas.'];
    }

    // La carga crea miles de modelos: publicar una sola invalidación por alcance
    // al terminar, incluso si alguna transacción anterior quedó confirmada.
    $changes = app(RealtimeChanges::class);
    $changes->begin();
    try {
    // Docentes del grupo más docentes de áreas especiales. Cuentas de acceso
    // sintéticas: contraseña aleatoria no publicada ni escrita en el repositorio.
    $tutorNames = ['Claudia Marcela Beltrán', 'Diana Carolina Pardo', 'Mónica Patricia Rojas',
        'María Camila Duarte', 'Natalia Andrea Herrera', 'Sandra Milena Torres', 'Paola Andrea Vargas'];
    $specialists = [
        'arte' => 'Catalina María Salcedo', 'fisica' => 'Andrés Felipe Moreno',
        'religion' => 'Laura Vanessa Gómez', 'tecnologia' => 'Julián David Cárdenas',
    ];
    $teachers = DB::transaction(function () use ($tutorNames, $specialists) {
        $result = [];
        foreach ([...array_combine(range(0, 6), $tutorNames), ...$specialists] as $key => $name) {
            $email = 'docente.auditoria.2026.'.Str::slug((string) $key).'@inmaculada.example.invalid';
            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::random(64),
                    'role' => 'docente', 'status' => 'active', 'must_change_password' => true]);
                $user->assignRole('docente');
                AuditLogger::tenant(null, 'CREATE', 'usuario_docente', (string) $user->id,
                    null, ['nombre' => $name, 'correo' => $email], 'Cuenta sintética local para auditoría académica.');
            }
            $result[(string) $key] = $user->id;
        }
        return $result;
    });

    // Las materias curriculares son la fuente de verdad; no añadimos materias
    // nuevas. Cada materia/grupo comparte una sola asignación docente.
    DB::transaction(function () use ($selected, $year, $teachers) {
        $subjectNames = DB::table('materias')->whereIn('id', $selected->flatMap(fn ($g) => $g->materias)->unique())
            ->pluck('nombre', 'id');
        foreach ($selected as $groupIndex => $group) {
            foreach ($group->materias as $subjectId) {
                $assignment = AsignacionDocente::where('ano_lectivo_id', $year->id)
                    ->where('grupo_id', $group->id)->where('materia_id', $subjectId)->first();
                $name = Str::lower($subjectNames->get($subjectId, ''));
                $specialty = str_contains($name, 'artíst') || str_contains($name, 'expresión') ? 'arte'
                    : (str_contains($name, 'física') || str_contains($name, 'corporal') ? 'fisica'
                    : (str_contains($name, 'religio') || str_contains($name, 'ética') ? 'religion'
                    : (str_contains($name, 'tecnolog') ? 'tecnologia' : (string) $groupIndex)));
                $teacherId = $teachers[$specialty];
                if (! $assignment) {
                    $assignment = AsignacionDocente::create(['ano_lectivo_id' => $year->id,
                        'grupo_id' => $group->id, 'materia_id' => $subjectId, 'docente_id' => $teacherId]);
                    AuditLogger::tenant(null, 'CREATE', 'asignacion_docente', (string) $assignment->id,
                        null, $assignment->toArray(), 'Se completó la materia del currículo para este grupo.');
                } elseif (! $assignment->docente_id) {
                    $assignment->update(['docente_id' => $teacherId]);
                    AuditLogger::tenant(null, 'UPDATE', 'asignacion_docente', (string) $assignment->id,
                        ['docente_id' => null], ['docente_id' => $teacherId], 'Docente sintético asignado a materia sin docente.');
                }
            }
        }
    });

    $optionIds = $scale->opciones->sortBy('orden')->values();
    $loaded = [];
    foreach ($periods as $period) {
        $loaded[] = DB::transaction(function () use ($selected, $year, $period, $optionIds) {
            $period = Periodo::lockForUpdate()->findOrFail($period->id);
            $preinformes = Preinforme::where('periodo_id', $period->id)->orderBy('orden')->get();
            $wasClosed = $period->estado === 'cerrado';
            if ($wasClosed) {
                $period->update(['estado' => 'abierto', 'reapertura_manual' => true]);
                AuditLogger::tenant(null, 'UPDATE', 'periodo', (string) $period->id,
                    ['estado' => 'cerrado'], ['estado' => 'abierto'],
                    'Reapertura excepcional para datos sintéticos; el registro de auditoría usa la fecha real.');
            } elseif ($period->estado !== 'abierto') {
                throw new RuntimeException("{$period->nombre} no se puede modificar.");
            }
            $newComponents = 0;
            $newActivities = 0;
            $newGrades = 0;
            foreach ($selected as $groupIndex => $group) {
                $preschool = $groupIndex < 3;
                $enrollments = Matricula::where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)
                    ->where('estado', 'activa')->orderBy('id')->get(['id']);
                $assignments = AsignacionDocente::where('ano_lectivo_id', $year->id)
                    ->where('grupo_id', $group->id)->orderBy('materia_id')->get();
                foreach ($assignments as $assignment) {
                    foreach ($preinformes as $pre) {
                        $component = ComponenteEvaluacion::where('asignacion_id', $assignment->id)
                            ->where('preinforme_id', $pre->id)->first();
                        if (! $component) {
                            $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id,
                                'periodo_id' => $period->id, 'preinforme_id' => $pre->id,
                                'nombre' => $pre->nombre, 'modo' => 'WEIGHTED_AVERAGE',
                                'peso' => $pre->peso, 'es_directo' => false, 'version' => 1]);
                            $newComponents++;
                        }
                        $activities = ActividadEvaluacion::where('componente_id', $component->id)->orderBy('id')->get();
                        if ($activities->isEmpty()) {
                            $firstDate = Carbon::parse($pre->fecha_inicio)->addDays(3);
                            $lastDate = Carbon::parse($pre->fecha_fin)->subDays(3);
                            if ($firstDate->greaterThan($lastDate)) {
                                throw new RuntimeException("{$pre->nombre} no tiene espacio para dos actividades.");
                            }
                            foreach ([['Seguimiento de clase', 45, $firstDate], ['Actividad integradora', 55, $lastDate]] as [$name, $weight, $date]) {
                                $activities->push(ActividadEvaluacion::create(['componente_id' => $component->id,
                                    'nombre' => $name, 'fecha' => $date->toDateString(), 'peso' => $weight, 'version' => 1]));
                                $newActivities++;
                            }
                        }
                        if ($component->modo === 'WEIGHTED_AVERAGE') {
                            $sum = $activities->sum(fn ($a) => (float) $a->peso);
                            if (abs($sum - 100.0) > 0.0001) {
                                throw new RuntimeException("Las actividades del componente {$component->id} no suman 100 %.");
                            }
                        }
                        foreach ($activities as $activity) {
                            $existing = DB::table('calificaciones')->where('actividad_id', $activity->id)
                                ->whereIn('matricula_id', $enrollments->modelKeys())->pluck('matricula_id')->flip();
                            $batch = [];
                            foreach ($enrollments as $enrollment) {
                                if ($existing->has($enrollment->id)) {
                                    continue;
                                }
                                $seed = (int) sprintf('%u', crc32("{$year->id}:{$group->id}:{$assignment->materia_id}:{$period->orden}:{$pre->orden}:{$activity->id}:{$enrollment->id}"));
                                if ($preschool) {
                                    // Distribución mixta para verificar las cuatro categorías.
                                    $choice = $optionIds[($seed % 17 < 2) ? 3 : (($seed % 17 < 5) ? 2 : (($seed % 17 < 11) ? 1 : 0))];
                                    $value = (string) $choice->valor_equivalente;
                                    $choiceId = $choice->id;
                                } else {
                                    $value = number_format(2.3 + ($seed % 28) / 10, 1, '.', '');
                                    $choiceId = null;
                                }
                                $batch[] = ['actividad_id' => $activity->id, 'matricula_id' => $enrollment->id,
                                    'valor' => $value, 'escala_opcion_id' => $choiceId,
                                    'observacion' => null, 'updated_by' => $assignment->docente_id,
                                    'version' => 1, 'created_at' => now(), 'updated_at' => now()];
                            }
                            foreach (array_chunk($batch, 500) as $chunk) {
                                DB::table('calificaciones')->insert($chunk);
                                $newGrades += count($chunk);
                            }
                        }
                    }
                }
                AuditLogger::tenant(null, 'CREATE', 'auditoria_planillas_2026', $group->id.':'.$period->id,
                    null, ['grado' => $group->grado, 'grupo' => $group->nombre, 'periodo' => $period->nombre,
                        'tipo' => 'datos sinteticos', 'preinformes' => 4],
                    'Carga local sintética; fechas de actividades históricas y auditoría de carga actual.');
            }
            if ($wasClosed) {
                $period->update(['estado' => 'cerrado', 'reapertura_manual' => false]);
                AuditLogger::tenant(null, 'UPDATE', 'periodo', (string) $period->id,
                    ['estado' => 'abierto'], ['estado' => 'cerrado'], 'Período cerrado nuevamente tras la carga.');
            }
            return ['periodo' => $period->nombre, 'componentes_nuevos' => $newComponents,
                'actividades_nuevas' => $newActivities, 'notas_nuevas' => $newGrades,
                'estado_final' => $period->estado];
        }, 3);
    }

    return ['modo' => 'aplicado', 'grupos' => $summary, 'periodos' => $loaded];
    } finally {
        $changes->flush();
    }
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
