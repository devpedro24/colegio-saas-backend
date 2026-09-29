<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
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
        'areas', 'materias', 'escalas', 'metodos', 'modelos', 'siee', 'curriculo', 'periodos',
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
                'siee_version' => 1,
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
            $periodosCopiados = $options['periodos'] ? $this->copyPeriods($source, $target) : 0;

            $this->saveCopyState($target, $source, $options, true);

            AuditLogger::tenant($actor, 'CREATE', 'ano_lectivo', (string) $target->id, null, [
                'origen_id' => $source->id,
                'destino' => $target->only(['id', 'nombre', 'tipo_calendario', 'fecha_inicio', 'fecha_fin', 'estado']),
                'opciones' => $options,
                'copiados' => array_map('count', $maps),
                'curriculo' => $options['curriculo'],
                'periodos' => $periodosCopiados,
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
                $created['periodos'] = $this->copyPeriods($source, $target);
            }

            $priorOptions = $state && ! $switching ? (json_decode($state->opciones, true) ?: []) : [];
            $savedOptions = $options;
            foreach ($priorOptions as $key => $enabled) {
                if (array_key_exists($key, $savedOptions)) {
                    $savedOptions[$key] = $savedOptions[$key] || (bool) $enabled;
                }
            }
            $this->saveCopyState($target->fresh(), $source, $savedOptions,
                $state ? ((bool) $state->reemplazable && $unchanged) : ! $hadConfiguration);

            AuditLogger::tenant($actor, 'UPDATE', 'ano_lectivo', (string) $target->id, null, [
                'origen_id' => $source->id, 'origen_anterior_id' => $previousSourceId,
                'opciones' => $options, 'copiados' => $created,
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
        foreach (self::TABLES as $key => $table) {
            $snapshot[$key] = DB::table($table)->where('ano_lectivo_id', $target->id)->orderBy('id')->get()->toArray();
        }

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
            if (in_array($table, $protected, true) || in_array($table, ['anos_lectivos', 'copias_configuracion_anual', 'periodos_sumatorios_legado'], true)) {
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
        $target->update(['siee' => null]);
        foreach (['curriculo', 'grupos', 'bloques', 'materias', 'grados', 'jornadas', 'niveles',
            'espacios', 'areas', 'escalas', 'metodos', 'modelos', 'periodos'] as $key) {
            DB::table(self::TABLES[$key])->where('ano_lectivo_id', $target->id)->delete();
        }
    }

    private function copyPeriods(AnoLectivo $source, AnoLectivo $target): int
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
            Periodo::create([
                'ano_lectivo_id' => $target->id,
                'nombre' => $period->nombre,
                'orden' => $period->orden,
                'fecha_inicio' => $start->toDateString(),
                'fecha_fin' => $end->toDateString(),
                'peso' => $period->peso,
                'estado' => Periodo::ESTADO_PLANIFICADO,
            ]);
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
