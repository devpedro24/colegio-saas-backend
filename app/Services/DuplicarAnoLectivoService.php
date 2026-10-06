<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaPregunta;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\ModeloPedagogico;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class DuplicarAnoLectivoService
{
    private const TABLES = [
        'jornadas' => 'jornadas', 'niveles' => 'niveles', 'grados' => 'grados',
        'grupos' => 'grupos', 'bloques' => 'bloques_horarios', 'espacios' => 'espacios_fisicos',
        'areas' => 'areas', 'materias' => 'materias', 'escalas' => 'escalas_valorativas',
        'metodos' => 'metodos_aprobacion', 'modelos' => 'modelos_pedagogicos',
        'curriculo' => 'materias_curriculares', 'periodos' => 'periodos',
    ];

    public const OPTIONS = [
        'jornadas', 'niveles', 'grados', 'grupos', 'bloques', 'espacios',
        'areas', 'materias', 'escalas', 'metodos', 'modelos', 'siee', 'curriculo', 'periodos', 'asistencia', 'aulas',
    ];

    /** @param array<string, mixed> $yearData
     *  @param array<string, bool> $options
     */
    public function duplicar(AnoLectivo $source, array $yearData, array $options, User $actor): AnoLectivo
    {
        $options = $this->withDependencies($options);

        return DB::transaction(function () use ($source, $yearData, $options, $actor): AnoLectivo {
            $source = AnoLectivo::lockForUpdate()->findOrFail($source->id);
            $target = AnoLectivo::create([
                ...$yearData,
                'estado' => AnoLectivo::ESTADO_PLANIFICADO,
                'siee' => null,
            ]);
            $maps = [];
            $copy = function (string $key, string $model, array $foreign = []) use ($source, $target, $options, &$maps): void {
                $maps[$key] = [];
                if (! $options[$key]) {
                    return;
                }
                foreach ($model::where('ano_lectivo_id', $source->id)->orderBy('id')->get() as $record) {
                    $new = $record->replicate();
                    $new->ano_lectivo_id = $target->id;
                    foreach ($foreign as $field => $parent) {
                        $old = $record->$field;
                        if ($old !== null && ! isset($maps[$parent][$old])) {
                            throw ValidationException::withMessages(['opciones' => "No se puede duplicar {$key}: falta un {$parent} relacionado."]);
                        }
                        $new->$field = $old === null ? null : $maps[$parent][$old];
                    }
                    $new->save();
                    $maps[$key][$record->id] = $new->id;
                }
            };

            $copy('jornadas', Jornada::class);
            $copy('niveles', Nivel::class);
            $copy('grados', Grado::class, ['nivel_id' => 'niveles']);
            $copy('bloques', BloqueHorario::class, ['jornada_id' => 'jornadas']);
            $copy('espacios', EspacioFisico::class);
            $copy('areas', Area::class);
            $copy('materias', Materia::class, ['area_id' => 'areas', 'nivel_id' => 'niveles']);
            $copy('grupos', Grupo::class, ['grado_id' => 'grados', 'jornada_id' => 'jornadas']);
            $copy('escalas', EscalaValorativa::class);
            $copy('metodos', MetodoAprobacion::class);
            $copy('modelos', ModeloPedagogico::class);

            if ($options['curriculo']) {
                foreach (DB::table('materias_curriculares')->where('ano_lectivo_id', $source->id)->get() as $row) {
                    if (! isset($maps['grados'][$row->grado_id], $maps['materias'][$row->materia_id])
                        || ($row->area_id !== null && ! isset($maps['areas'][$row->area_id]))) {
                        throw ValidationException::withMessages(['opciones' => 'El currículo contiene grados, materias o áreas que no se pueden duplicar.']);
                    }
                    DB::table('materias_curriculares')->insert([
                        'ano_lectivo_id' => $target->id,
                        'grado_id' => $maps['grados'][$row->grado_id],
                        'materia_id' => $maps['materias'][$row->materia_id],
                        'area_id' => $row->area_id === null ? null : ($maps['areas'][$row->area_id] ?? null),
                        'peso_area' => $row->peso_area,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($options['siee'] && $source->siee) {
                $siee = $source->siee;
                if ($target->periodo_sumatorio && ($siee['modo_anual'] ?? null) === 'MANUAL') {
                    throw ValidationException::withMessages(['opciones' => 'El período sumatorio requiere un cálculo anual simple o ponderado en el SIEE.']);
                }
                if ((isset($siee['escala_id']) && ! isset($maps['escalas'][$siee['escala_id']]))
                    || (isset($siee['metodo_id']) && ! isset($maps['metodos'][$siee['metodo_id']]))) {
                    throw ValidationException::withMessages(['opciones' => 'El SIEE referencia una escala o método que no se puede duplicar.']);
                }
                $siee['escala_id'] = $maps['escalas'][$siee['escala_id']] ?? null;
                $siee['metodo_id'] = $maps['metodos'][$siee['metodo_id']] ?? null;
                $target->update(['siee' => $siee]);
            }
            $periodosCopiados = $options['periodos'] ? $this->copyPeriods($source, $target, $actor) : 0;
            $preparacionesCopiadas = ($options['curriculo'] || $options['periodos'])
                ? $this->copyEvaluationPreparations($source, $target) : 0;
            $asistenciaCopiada = $options['asistencia']
                ? $this->copyAttendancePolicy($source, $target) : 0;
            $aulasCopiadas = $options['aulas'] ? $this->copyAulas($source, $target, $maps) : 0;

            $this->saveCopyState($target, $source, $options, ! $options['aulas']);

            AuditLogger::tenant($actor, 'CREATE', 'ano_lectivo', (string) $target->id, null, [
                'origen_id' => $source->id,
                'destino' => $target->only(['id', 'nombre', 'tipo_calendario', 'fecha_inicio', 'fecha_fin', 'estado']),
                'opciones' => $options,
                'copiados' => array_map('count', $maps),
                'curriculo' => $options['curriculo'],
                'periodos' => $periodosCopiados,
                'preparaciones_evaluacion' => $preparacionesCopiadas,
                'politica_asistencia' => $asistenciaCopiada,
                'aulas' => $aulasCopiadas,
            ], 'Duplicación de año lectivo sin asignaciones, horarios ni calificaciones.');

            return $target;
        });
    }

    /**
     * Completa secciones omitidas al duplicar, sin modificar registros ya
     * configurados en el año destino. También puede repetirse sin duplicarlos.
     *
     * @param array<string, bool> $options
     */
    public function copiarConfiguracion(AnoLectivo $source, AnoLectivo $target, array $options, User $actor): AnoLectivo
    {
        $options = $this->withDependencies($options);
        if (! in_array(true, $options, true)) {
            throw ValidationException::withMessages(['opciones' => 'Selecciona al menos una configuración para copiar.']);
        }

        return DB::transaction(function () use ($source, $target, $options, $actor): AnoLectivo {
            $source = AnoLectivo::query()->lockForUpdate()->findOrFail($source->id);
            $target = AnoLectivo::query()->lockForUpdate()->findOrFail($target->id);
            if ($source->id === $target->id) {
                throw ValidationException::withMessages(['origen_id' => 'El año de origen debe ser distinto del destino.']);
            }
            if ($target->estaCerrado()) {
                throw ValidationException::withMessages(['destino' => 'No se puede cambiar la configuración de un año cerrado o archivado.']);
            }

            $state = DB::table('copias_configuracion_anual')->where('ano_lectivo_id', $target->id)->lockForUpdate()->first();
            $previousSourceId = $state?->origen_id ?? $this->historicalSourceId($target);
            $hadConfiguration = in_array(true, $this->filledOptions($target), true);
            $unchanged = $state && $state->huella === $this->fingerprint($target);
            $switching = $previousSourceId !== null && (int) $previousSourceId !== (int) $source->id;
            if ($switching) {
                if ($target->estado !== AnoLectivo::ESTADO_PLANIFICADO) {
                    throw ValidationException::withMessages(['origen_id' => 'Solo se puede reemplazar la configuración copiada de un año planificado.']);
                }
                if (! $state || ! $state->reemplazable || $state->huella !== $this->fingerprint($target)) {
                    throw ValidationException::withMessages(['origen_id' => 'La configuración del año destino contiene datos anteriores o cambios posteriores a la copia. No se reemplazó; revísala antes de cambiar el origen.']);
                }
                $this->assertCanReplace($target);
                $this->clearConfiguration($target);
            }

            $maps = [];
            $created = array_fill_keys(self::OPTIONS, 0);
            $copy = function (string $key, string $model, array $identity, array $foreign = []) use ($source, $target, $options, &$maps, &$created): void {
                $maps[$key] = [];
                if (! $options[$key]) {
                    return;
                }
                foreach ($model::where('ano_lectivo_id', $source->id)->orderBy('id')->get() as $record) {
                    $candidate = $record->replicate();
                    $candidate->ano_lectivo_id = $target->id;
                    foreach ($foreign as $field => $parent) {
                        $old = $record->$field;
                        if ($old !== null && ! isset($maps[$parent][$old])) {
                            throw ValidationException::withMessages(['opciones' => "No se puede copiar {$key}: falta un {$parent} relacionado."]);
                        }
                        $candidate->$field = $old === null ? null : $maps[$parent][$old];
                    }

                    $query = $model::query()->where('ano_lectivo_id', $target->id);
                    if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
                        $query->withTrashed();
                    }
                    foreach ($identity as $field) {
                        $query->where($field, $candidate->$field);
                    }
                    $existing = $query->first();
                    if ($existing) {
                        if ($existing->deleted_at ?? null) {
                            throw ValidationException::withMessages(['opciones' => "No se puede copiar {$key}: existe un registro eliminado con la misma identidad en el año destino."]);
                        }
                        $maps[$key][$record->id] = $existing->id;
                        continue;
                    }
                    $candidate->save();
                    $maps[$key][$record->id] = $candidate->id;
                    $created[$key]++;
                }
            };

            $copy('jornadas', Jornada::class, ['sede_id', 'nombre']);
            $copy('niveles', Nivel::class, ['nivel_educativo']);
            $copy('grados', Grado::class, ['nivel_id', 'nombre'], ['nivel_id' => 'niveles']);
            $copy('bloques', BloqueHorario::class, ['jornada_id', 'nombre'], ['jornada_id' => 'jornadas']);
            $copy('espacios', EspacioFisico::class, ['sede_id', 'nombre']);
            $copy('areas', Area::class, ['nombre']);
            $copy('materias', Materia::class, ['area_id', 'nombre'], ['area_id' => 'areas', 'nivel_id' => 'niveles']);
            $copy('grupos', Grupo::class, ['grado_id', 'jornada_id', 'nombre'], ['grado_id' => 'grados', 'jornada_id' => 'jornadas']);
            $copy('escalas', EscalaValorativa::class, ['nivel_educativo', 'nombre']);
            $copy('metodos', MetodoAprobacion::class, ['ambito', 'calculo_nota']);
            $copy('modelos', ModeloPedagogico::class, ['nivel_educativo']);

            if ($options['curriculo']) {
                foreach (DB::table('materias_curriculares')->where('ano_lectivo_id', $source->id)->get() as $row) {
                    if (! isset($maps['grados'][$row->grado_id], $maps['materias'][$row->materia_id])
                        || ($row->area_id !== null && ! isset($maps['areas'][$row->area_id]))) {
                        throw ValidationException::withMessages(['opciones' => 'El currículo contiene grados, materias o áreas que no se pueden copiar.']);
                    }
                    $gradeId = $maps['grados'][$row->grado_id];
                    $subjectId = $maps['materias'][$row->materia_id];
                    if (! DB::table('materias_curriculares')->where('ano_lectivo_id', $target->id)
                        ->where('grado_id', $gradeId)->where('materia_id', $subjectId)->exists()) {
                        DB::table('materias_curriculares')->insert([
                            'ano_lectivo_id' => $target->id,
                            'grado_id' => $gradeId,
                            'materia_id' => $subjectId,
                            'area_id' => $row->area_id === null ? null : $maps['areas'][$row->area_id],
                            'peso_area' => $row->peso_area,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $created['curriculo']++;
                    }
                }
            }
            if ($options['siee'] && $source->siee && ! $target->siee) {
                $siee = $source->siee;
                if ($target->periodo_sumatorio && ($siee['modo_anual'] ?? null) === 'MANUAL') {
                    throw ValidationException::withMessages(['opciones' => 'El período sumatorio requiere un cálculo anual simple o ponderado en el SIEE.']);
                }
                if ((isset($siee['escala_id']) && ! isset($maps['escalas'][$siee['escala_id']]))
                    || (isset($siee['metodo_id']) && ! isset($maps['metodos'][$siee['metodo_id']]))) {
                    throw ValidationException::withMessages(['opciones' => 'El SIEE referencia una escala o método que no se puede copiar.']);
                }
                $siee['escala_id'] = $maps['escalas'][$siee['escala_id']] ?? null;
                $siee['metodo_id'] = $maps['metodos'][$siee['metodo_id']] ?? null;
                $target->update(['siee' => $siee]);
                $created['siee'] = 1;
            }
            if ($options['periodos']) {
                $created['periodos'] = $this->copyPeriods($source, $target, $actor);
            }
            $preparacionesCopiadas = ($options['curriculo'] || $options['periodos'])
                ? $this->copyEvaluationPreparations($source, $target) : 0;
            $created['asistencia'] = $options['asistencia']
                ? $this->copyAttendancePolicy($source, $target) : 0;
            $created['aulas'] = $options['aulas'] ? $this->copyAulas($source, $target, $maps) : 0;

            $priorOptions = $state && ! $switching ? (json_decode($state->opciones, true) ?: []) : [];
            $savedOptions = $options;
            foreach ($priorOptions as $key => $enabled) {
                if (array_key_exists($key, $savedOptions)) {
                    $savedOptions[$key] = $savedOptions[$key] || (bool) $enabled;
                }
            }
            $this->saveCopyState($target->fresh(), $source, $savedOptions,
                ! $savedOptions['aulas'] && ($state ? ((bool) $state->reemplazable && $unchanged) : ! $hadConfiguration));

            AuditLogger::tenant($actor, 'UPDATE', 'ano_lectivo', (string) $target->id, null, [
                'origen_id' => $source->id, 'origen_anterior_id' => $previousSourceId,
                'opciones' => $options, 'copiados' => $created,
                'preparaciones_evaluacion' => $preparacionesCopiadas,
            ], $switching ? 'Cambio de origen de copia académica, con verificación de integridad.'
                : 'Copia posterior de configuración académica sin sobrescribir datos existentes.');

            return $target->fresh();
        });
    }

    /** Estado real de las secciones y procedencia conocida para el modal de copia. */
    public function copyStatus(AnoLectivo $target): array
    {
        $state = DB::table('copias_configuracion_anual')->where('ano_lectivo_id', $target->id)->first();

        return [
            'origen_id' => $state?->origen_id ?? $this->historicalSourceId($target),
            'opciones' => $this->filledOptions($target),
            'reemplazable' => $target->estado === AnoLectivo::ESTADO_PLANIFICADO
                && $state !== null && (bool) $state->reemplazable
                && $state->huella === $this->fingerprint($target),
        ];
    }

    private function filledOptions(AnoLectivo $target): array
    {
        $filled = array_fill_keys(self::OPTIONS, false);
        foreach (self::TABLES as $key => $table) {
            $filled[$key] = DB::table($table)->where('ano_lectivo_id', $target->id)
                ->when(Schema::hasColumn($table, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();
        }
        $filled['siee'] = $target->siee !== null;
        $filled['asistencia'] = DB::table('asistencia_politicas')
            ->where('ano_lectivo_id', $target->id)->exists();
        $filled['aulas'] = DB::table('aulas')->where('ano_lectivo_id', $target->id)->exists();

        return $filled;
    }

    private function historicalSourceId(AnoLectivo $target): ?int
    {
        foreach (DB::table('audit_logs')->where('recurso', 'ano_lectivo')
            ->where('recurso_id', (string) $target->id)->orderByDesc('id')->pluck('valor_nuevo') as $json) {
            $value = json_decode($json ?? '{}', true);
            if (isset($value['origen_id'])) {
                return (int) $value['origen_id'];
            }
        }

        return null;
    }

    private function fingerprint(AnoLectivo $target): string
    {
        $snapshot = ['siee' => $target->fresh()->siee];
        $attendancePolicy = DB::table('asistencia_politicas')->where('ano_lectivo_id', $target->id)->first();
        if ($attendancePolicy) $snapshot['asistencia_politica'] = $attendancePolicy;
        $aulaIds = DB::table('aulas')->where('ano_lectivo_id', $target->id)->pluck('id');
        $snapshot['aulas'] = DB::table('aulas')->whereIn('id', $aulaIds)->orderBy('id')->get()->toArray();
        $sectionIds = DB::table('aula_secciones')->whereIn('aula_id', $aulaIds)->pluck('id');
        $snapshot['aula_secciones'] = DB::table('aula_secciones')->whereIn('id', $sectionIds)->orderBy('id')->get()->toArray();
        $resourceIds = DB::table('aula_recursos')->whereIn('seccion_id', $sectionIds)->pluck('id');
        $snapshot['aula_recursos'] = DB::table('aula_recursos')->whereIn('id', $resourceIds)->orderBy('id')->get()->toArray();
        $preinformes = DB::table('preinformes')->whereIn('periodo_id', Periodo::where('ano_lectivo_id', $target->id)->select('id'))->orderBy('id')->get()->toArray();
        if ($preinformes !== []) $snapshot['preinformes'] = $preinformes;
        foreach (self::TABLES as $key => $table) {
            $snapshot[$key] = DB::table($table)->where('ano_lectivo_id', $target->id)->orderBy('id')->get()->toArray();
            if ($key === 'periodos') foreach ($snapshot[$key] as $row) {
                // New nullable metadata must not invalidate untouched historical copies.
                if ($row->configuracion_notas === null && (int) $row->version_notas === 0) unset($row->configuracion_notas, $row->version_notas);
            }
        }
        $curriculumIds = DB::table('materias_curriculares')->where('ano_lectivo_id', $target->id)->pluck('id');
        $snapshot['preparaciones_evaluacion'] = DB::table('preparaciones_evaluacion')
            ->whereIn('materia_curricular_id', $curriculumIds)->orderBy('id')->get()->toArray();
        $preparationIds = collect($snapshot['preparaciones_evaluacion'])->pluck('id');
        $snapshot['componentes_preparados'] = DB::table('componentes_preparados')
            ->whereIn('preparacion_id', $preparationIds)->orderBy('id')->get()->toArray();
        $snapshot['actividades_preparadas'] = DB::table('actividades_preparadas')
            ->whereIn('componente_id', collect($snapshot['componentes_preparados'])->pluck('id'))
            ->orderBy('id')->get()->toArray();

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function saveCopyState(AnoLectivo $target, AnoLectivo $source, array $options, bool $replaceable): void
    {
        DB::table('copias_configuracion_anual')->updateOrInsert(
            ['ano_lectivo_id' => $target->id],
            ['origen_id' => $source->id, 'opciones' => json_encode($options, JSON_THROW_ON_ERROR),
                'huella' => $this->fingerprint($target), 'reemplazable' => $replaceable,
                'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function assertCanReplace(AnoLectivo $target): void
    {
        $protected = array_values(self::TABLES);
        $ids = [];
        foreach (['grupos' => 'grupo_id', 'materias' => 'materia_id', 'grados' => 'grado_id',
            'jornadas' => 'jornada_id', 'bloques_horarios' => 'bloque_horario_id',
            'espacios_fisicos' => 'espacio_fisico_id', 'periodos' => 'periodo_id'] as $table => $field) {
            $ids[$field] = DB::table($table)->where('ano_lectivo_id', $target->id)->pluck('id')->all();
        }
        $ids['period_id'] = $ids['periodo_id'];
        foreach (Schema::getTables() as $tableInfo) {
            $table = is_array($tableInfo) ? $tableInfo['name'] : $tableInfo->name;
            if (in_array($table, $protected, true) || in_array($table, [
                'anos_lectivos', 'copias_configuracion_anual', 'periodos_sumatorios_legado',
                'preparaciones_evaluacion', 'componentes_preparados', 'actividades_preparadas',
                'preinformes', 'asistencia_politicas',
            ], true)) {
                continue;
            }
            if (Schema::hasColumn($table, 'ano_lectivo_id')
                && DB::table($table)->where('ano_lectivo_id', $target->id)->exists()) {
                throw ValidationException::withMessages(['origen_id' => 'Este año ya tiene información académica asociada. No se puede reemplazar la configuración copiada.']);
            }
            foreach ($ids as $field => $values) {
                if ($values && Schema::hasColumn($table, $field)
                    && DB::table($table)->whereIn($field, $values)->exists()) {
                    throw ValidationException::withMessages(['origen_id' => "Hay registros de {$table} que usan esta configuración. No se reemplazó."]);
                }
            }
        }
    }

    private function clearConfiguration(AnoLectivo $target): void
    {
        DB::table('asistencia_politicas')->where('ano_lectivo_id', $target->id)->delete();
        $target->update(['siee' => null]);
        $curriculumIds = DB::table('materias_curriculares')->where('ano_lectivo_id', $target->id)->pluck('id');
        $preparationIds = DB::table('preparaciones_evaluacion')
            ->whereIn('materia_curricular_id', $curriculumIds)->pluck('id');
        $componentIds = DB::table('componentes_preparados')
            ->whereIn('preparacion_id', $preparationIds)->pluck('id');
        DB::table('actividades_preparadas')->whereIn('componente_id', $componentIds)->delete();
        DB::table('componentes_preparados')->whereIn('preparacion_id', $preparationIds)->delete();
        DB::table('preparaciones_evaluacion')->whereIn('id', $preparationIds)->delete();
        DB::table('preinformes')->whereIn('periodo_id', Periodo::where('ano_lectivo_id', $target->id)->select('id'))->delete();
        foreach (['curriculo', 'grupos', 'bloques', 'materias', 'grados', 'jornadas', 'niveles',
            'espacios', 'areas', 'escalas', 'metodos', 'modelos', 'periodos'] as $key) {
            DB::table(self::TABLES[$key])->where('ano_lectivo_id', $target->id)->delete();
        }
    }

    /** Una política anual nueva, nunca marcas, alertas ni solicitudes históricas. */
    private function copyAttendancePolicy(AnoLectivo $source, AnoLectivo $target): int
    {
        $policy = DB::table('asistencia_politicas')->where('ano_lectivo_id', $source->id)->first();
        if (! $policy || DB::table('asistencia_politicas')->where('ano_lectivo_id', $target->id)->exists()) {
            return 0;
        }

        DB::table('asistencia_politicas')->insert([
            'ano_lectivo_id' => $target->id,
            'max_faltas' => $policy->max_faltas,
            'max_porcentaje' => $policy->max_porcentaje,
            'combinacion' => $policy->combinacion,
            'ambito' => $policy->ambito,
            'tardes_por_falta' => $policy->tardes_por_falta,
            'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return 1;
    }

    /** Reutiliza materiales; nunca copia matrículas, entregas, intentos ni calificaciones. */
    private function copyAulas(AnoLectivo $source, AnoLectivo $target, array $maps): int
    {
        app(AcademicPlanAccess::class)->requireAula();
        $copied = 0;
        foreach (Aula::where('ano_lectivo_id', $source->id)->with('secciones.recursos')->get() as $oldAula) {
            $group = $maps['grupos'][$oldAula->grupo_id] ?? null;
            $subject = $maps['materias'][$oldAula->materia_id] ?? null;
            if (! $group || ! $subject) {
                throw ValidationException::withMessages(['opciones' => 'Un aula no tiene grupo o asignatura equivalente en el destino.']);
            }
            $targetGroup = Grupo::findOrFail($group);
            abort_unless(DB::table('materias_curriculares')->where('ano_lectivo_id', $target->id)
                ->where('grado_id', $targetGroup->grado_id)->where('materia_id', $subject)->exists(), 422,
                'El currículo destino no incluye una asignatura del aula.');
            $newAula = Aula::firstOrCreate(['grupo_id' => $group, 'materia_id' => $subject],
                ['ano_lectivo_id' => $target->id, 'portada_token' => $oldAula->portada_token]);
            // Un aula que ya existe en destino puede tener trabajo propio: no insertamos
            // secciones adicionales de forma silenciosa en ella ni alteramos sus materiales.
            if (! $newAula->wasRecentlyCreated) continue;
            $copied++;
            foreach ($oldAula->secciones as $oldSection) {
                $oldPeriod = Periodo::findOrFail($oldSection->periodo_id);
                $newPeriod = Periodo::where('ano_lectivo_id', $target->id)->where('orden', $oldPeriod->orden)->first();
                if (! $newPeriod) throw ValidationException::withMessages(['opciones' => 'Falta un período equivalente para el aula.']);
                $newPre = null;
                if ($oldSection->preinforme_id) {
                    $oldPre = Preinforme::findOrFail($oldSection->preinforme_id);
                    $newPre = Preinforme::where('periodo_id', $newPeriod->id)->where('orden', $oldPre->orden)->first();
                    if (! $newPre) throw ValidationException::withMessages(['opciones' => 'Falta un preinforme equivalente para el aula.']);
                }
                $newSection = AulaSeccion::firstOrCreate(['aula_id' => $newAula->id, 'seccion_origen_id' => $oldSection->id],
                    ['periodo_id' => $newPeriod->id, 'preinforme_id' => $newPre?->id, 'titulo' => $oldSection->titulo,
                        'orden' => $oldSection->orden, 'visible_estudiantes' => false, 'autor_id' => $oldSection->autor_id]);
                foreach ($oldSection->recursos as $oldResource) {
                    if (AulaRecurso::where('seccion_id', $newSection->id)->where('recurso_origen_id', $oldResource->id)->exists()) continue;
                    $config = $oldResource->configuracion ?? [];
                    if ($oldResource->llevar_planilla) $config['vinculo_anterior_requiere_revision'] = true;
                    $newResource = AulaRecurso::create(['seccion_id' => $newSection->id, 'tipo' => $oldResource->tipo,
                        'titulo' => $oldResource->titulo, 'contenido' => $oldResource->contenido, 'configuracion' => $config,
                        'estado' => $oldResource->estado, 'visible_estudiantes' => false,
                        'calificable' => $oldResource->calificable, 'llevar_planilla' => false, 'actividad_id' => null,
                        'peso' => $oldResource->peso, 'disponible_desde' => null, 'disponible_hasta' => null,
                        'fecha_limite' => null, 'zona_publicacion' => null, 'orden' => $oldResource->orden,
                        'autor_id' => $oldResource->autor_id, 'recurso_origen_id' => $oldResource->id, 'version' => 1]);
                    foreach ($oldResource->preguntas as $question) {
                        AulaPregunta::create(['recurso_id' => $newResource->id, 'tipo' => $question->tipo,
                            'enunciado' => $question->enunciado, 'opciones' => $question->opciones,
                            'respuesta_correcta' => $question->respuesta_correcta, 'puntos' => $question->puntos,
                            'orden' => $question->orden]);
                    }
                    foreach (DB::table('aula_adjuntos')->where('recurso_id', $oldResource->id)->whereNull('entrega_id')->get() as $file) {
                        DB::table('aula_adjuntos')->insert(['recurso_id' => $newResource->id, 'entrega_id' => null,
                            'archivo_token' => $file->archivo_token, 'nombre' => $file->nombre,
                            'autor_id' => $file->autor_id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        }

        return $copied;
    }

    private function copyPeriods(AnoLectivo $source, AnoLectivo $target, User $actor): int
    {
        $copied = 0;
        $maxOrder = $target->num_periodos;
        foreach (Periodo::where('ano_lectivo_id', $source->id)->orderBy('orden')->get() as $period) {
            if ($period->orden > $maxOrder) {
                throw ValidationException::withMessages(['opciones' => 'El año destino admite menos períodos que el año de origen. Ajusta su cantidad antes de copiarlos.']);
            }
            $existing = Periodo::withTrashed()->where('ano_lectivo_id', $target->id)->where('orden', $period->orden)->first();
            if ($existing) {
                if ($existing->trashed()) {
                    throw ValidationException::withMessages(['opciones' => 'El año destino tiene un período eliminado con el mismo orden. Revísalo antes de copiar.']);
                }
                $this->copyPreinformes($period, $existing, $actor);
                continue;
            }
            $sameCalendarStart = $source->fecha_inicio->format('m-d') === $target->fecha_inicio->format('m-d');
            $yearShift = $target->fecha_inicio->year - $source->fecha_inicio->year;
            $start = $sameCalendarStart
                ? $period->fecha_inicio->copy()->addYearsNoOverflow($yearShift)
                : $target->fecha_inicio->copy()->addDays((int) $source->fecha_inicio->diffInDays($period->fecha_inicio));
            $end = $period->fecha_fin->isSameDay($source->fecha_fin)
                ? $target->fecha_fin->copy()
                : ($sameCalendarStart
                    ? $period->fecha_fin->copy()->addYearsNoOverflow($yearShift)
                    : $target->fecha_inicio->copy()->addDays((int) $source->fecha_inicio->diffInDays($period->fecha_fin)));
            if ($start->lt($target->fecha_inicio) || $end->gt($target->fecha_fin) || $start->gt($end)) {
                throw ValidationException::withMessages(['opciones' => 'Las fechas copiadas de los períodos no caben en el año destino. Ajusta las fechas antes de copiar.']);
            }
            if (Periodo::where('ano_lectivo_id', $target->id)
                ->whereDate('fecha_inicio', '<=', $end->toDateString())
                ->whereDate('fecha_fin', '>=', $start->toDateString())->exists()) {
                throw ValidationException::withMessages(['opciones' => 'Los períodos copiados se cruzan con los ya configurados en el año destino.']);
            }
            $newPeriod = Periodo::create([
                'ano_lectivo_id' => $target->id,
                'nombre' => $period->nombre,
                'orden' => $period->orden,
                'fecha_inicio' => $start->toDateString(),
                'fecha_fin' => $end->toDateString(),
                'peso' => $period->peso,
                'estado' => Periodo::ESTADO_PLANIFICADO,
            ]);
            $this->copyPreinformes($period, $newPeriod, $actor);
            $copied++;
        }

        return $copied;
    }

    private function copyPreinformes(Periodo $source, Periodo $target, User $actor): void
    {
        if ($source->configuracion_notas === null || $target->configuracion_notas !== null) return;
        abort_if($target->estaCerrado(), 422, 'No se pueden copiar preinformes a un período cerrado.');
        abort_if(\App\Models\Academico\ComponenteEvaluacion::where('periodo_id', $target->id)->exists(), 422, 'El período destino ya tiene una planilla; no se cambió su configuración.');
        if ($source->configuracion_notas['usar_preinformes'] ?? false) {
            abort_unless($actor->can('academico.preinformes.gestionar'), 403);
            app(AcademicPlanAccess::class)->requirePreinformes();
        }
        foreach (Preinforme::where('periodo_id', $source->id)->orderBy('orden')->get() as $pre) {
            $dates = [];
            foreach (['fecha_inicio', 'fecha_fin'] as $field) {
                $dates[$field] = null;
                if ($pre->$field) {
                    $date = $target->fecha_inicio->copy()->addDays((int) $source->fecha_inicio->diffInDays(\Illuminate\Support\Carbon::parse($pre->$field)));
                    abort_if($date->gt($target->fecha_fin), 422, 'Las fechas de los preinformes no caben en el período destino.');
                    $dates[$field] = $date->toDateString();
                }
            }
            Preinforme::create(['periodo_id' => $target->id, 'nombre' => $pre->nombre, 'orden' => $pre->orden, 'peso' => $pre->peso, ...$dates]);
        }
        $target->update(['configuracion_notas' => $source->configuracion_notas, 'version_notas' => 1]);
    }

    /** Copia solo definiciones preparatorias, nunca asignaciones, planillas ni notas. */
    private function copyEvaluationPreparations(AnoLectivo $source, AnoLectivo $target): int
    {
        $copied = 0;
        $preparations = DB::table('preparaciones_evaluacion as p')
            ->join('materias_curriculares as c', 'c.id', '=', 'p.materia_curricular_id')
            ->join('grados as g', 'g.id', '=', 'c.grado_id')
            ->join('niveles as n', 'n.id', '=', 'g.nivel_id')
            ->join('materias as m', 'm.id', '=', 'c.materia_id')
            ->join('periodos as term', 'term.id', '=', 'p.periodo_id')
            ->where('c.ano_lectivo_id', $source->id)
            ->select('p.*', 'g.nombre as grado_nombre', 'n.nivel_educativo',
                'm.nombre as materia_nombre', 'term.orden as periodo_orden')
            ->orderBy('p.id')->get();
        foreach ($preparations as $preparation) {
            $matches = DB::table('materias_curriculares as c')
                ->join('grados as g', 'g.id', '=', 'c.grado_id')
                ->join('niveles as n', 'n.id', '=', 'g.nivel_id')
                ->join('materias as m', 'm.id', '=', 'c.materia_id')
                ->where('c.ano_lectivo_id', $target->id)
                ->where('g.nombre', $preparation->grado_nombre)
                ->where('n.nivel_educativo', $preparation->nivel_educativo)
                ->where('m.nombre', $preparation->materia_nombre)
                ->pluck('c.id');
            $targetPeriod = Periodo::where('ano_lectivo_id', $target->id)
                ->where('orden', $preparation->periodo_orden)->first();
            if ($matches->isEmpty() || ! $targetPeriod) {
                continue; // Sección pendiente: puede copiarse después al completar currículo y períodos.
            }
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['opciones' =>
                    'Hay materias curriculares ambiguas en el año destino. Revisa sus nombres antes de copiar la preparación.']);
            }
            $targetCurriculumId = $matches->first();
            if (DB::table('preparaciones_evaluacion')->where('materia_curricular_id', $targetCurriculumId)
                ->where('periodo_id', $targetPeriod->id)->exists()) {
                continue; // Una configuración local existente nunca se sobrescribe.
            }
            $sourcePeriod = Periodo::findOrFail($preparation->periodo_id);
            $targetPreparationId = DB::table('preparaciones_evaluacion')->insertGetId([
                'materia_curricular_id' => $targetCurriculumId, 'periodo_id' => $targetPeriod->id,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('componentes_preparados')->where('preparacion_id', $preparation->id)->orderBy('id')->get() as $component) {
                $targetComponentId = DB::table('componentes_preparados')->insertGetId([
                    'preparacion_id' => $targetPreparationId, 'nombre' => $component->nombre,
                    'modo' => $component->modo, 'peso' => $component->peso,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (DB::table('actividades_preparadas')->where('componente_id', $component->id)->orderBy('id')->get() as $activity) {
                    $shift = (int) $sourcePeriod->fecha_inicio->diffInDays(\Illuminate\Support\Carbon::parse($activity->fecha));
                    $date = $targetPeriod->fecha_inicio->copy()->addDays($shift);
                    if ($date->gt($targetPeriod->fecha_fin)) {
                        throw ValidationException::withMessages(['opciones' =>
                            'Una actividad preparada no cabe en las fechas del período destino. Ajusta los períodos antes de copiar.']);
                    }
                    DB::table('actividades_preparadas')->insert([
                        'componente_id' => $targetComponentId, 'nombre' => $activity->nombre,
                        'fecha' => $date->toDateString(), 'peso' => $activity->peso,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $copied++;
        }

        return $copied;
    }

    /** @param array<string, bool> $options
     *  @return array<string, bool>
     */
    private function withDependencies(array $options): array
    {
        $resolved = array_fill_keys(self::OPTIONS, false);
        foreach ($resolved as $key => $_) {
            $resolved[$key] = (bool) ($options[$key] ?? false);
        }
        if ($resolved['aulas']) {
            $resolved['grupos'] = $resolved['materias'] = $resolved['curriculo'] = $resolved['periodos'] = true;
        }
        if ($resolved['curriculo']) {
            $resolved['grados'] = $resolved['materias'] = $resolved['escalas'] = $resolved['metodos'] = $resolved['siee'] = true;
        }
        if ($resolved['siee']) {
            $resolved['escalas'] = $resolved['metodos'] = true;
        }
        if ($resolved['grupos']) {
            $resolved['grados'] = $resolved['jornadas'] = true;
        }
        if ($resolved['bloques']) {
            $resolved['jornadas'] = true;
        }
        if ($resolved['materias']) {
            $resolved['areas'] = $resolved['niveles'] = true;
        }
        if ($resolved['grados']) {
            $resolved['niveles'] = true;
        }

        return $resolved;
    }
}
