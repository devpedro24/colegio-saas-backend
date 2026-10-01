<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Area;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\Nivel;
use App\Models\Academico\Sede;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Opciones de formularios académicos; limita catálogos con más de 20 elementos. */
class AcademicOptionsController extends Controller
{
    use PaginatesRequests;

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->boolean('opaque')) {
            return $this->opaqueOptions($request);
        }

        $filters = $request->validate([
            'tipo' => ['required', Rule::in(['areas', 'materias', 'grados', 'grupos', 'docentes', 'bloques', 'espacios', 'estudiantes'])],
            'ano_lectivo_id' => ['nullable', 'integer', 'exists:anos_lectivos,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'integer', 'min:1'],
            'area_id' => ['nullable', 'integer'], 'nivel_id' => ['nullable', 'integer'],
            'compatible_nivel_id' => ['nullable', 'integer', 'min:1'],
            'sede_id' => ['nullable', 'integer'], 'jornada_id' => ['nullable', 'integer'],
            'disponibles' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $manage = $user->esSuperadminPlataforma()
            || $user->can('academico.plan_estudios.gestionar') || $user->can('academico.estructura.gestionar')
            || $user->can('academico.configurar');
        $teacher = $user->hasRole('docente');
        $student = $user->hasRole('estudiante');
        abort_unless($manage || $teacher || $student, 403);
        $yearId = $filters['ano_lectivo_id'] ?? null;
        $tipo = $filters['tipo'];

        $query = match ($tipo) {
            'areas' => Area::query(),
            'materias' => Materia::with(['area:id,nombre', 'nivel:id,nombre']),
            'grados' => Grado::with('nivel:id,nombre,nivel_educativo'),
            'grupos' => Grupo::with(['grado.nivel', 'sede', 'jornada']),
            'docentes' => User::role('docente')->where('status', 'active'),
            'bloques' => BloqueHorario::with('jornada:id,nombre,sede_id'),
            'espacios' => EspacioFisico::with('sede:id,nombre'),
            'estudiantes' => User::role('estudiante')->where('status', 'active'),
        };
        if (! in_array($tipo, ['docentes', 'estudiantes'], true)) {
            $query->when($yearId, fn (Builder $q) => $q->where('ano_lectivo_id', $yearId));
        }
        if (! $manage) {
            $this->scopeToUser($query, $tipo, $user, $teacher);
        }
        if ($tipo === 'estudiantes' && ! empty($filters['disponibles']) && $yearId) {
            $query->whereNotIn('id', Matricula::where('ano_lectivo_id', $yearId)
                ->where('estado', 'activa')->select('estudiante_id'));
        }
        $query->when(isset($filters['area_id']) && $tipo === 'materias', fn (Builder $q) => $q->where('area_id', $filters['area_id']))
            ->when(isset($filters['nivel_id']) && in_array($tipo, ['materias', 'grados'], true), fn (Builder $q) => $q->where('nivel_id', $filters['nivel_id']))
            ->when(isset($filters['compatible_nivel_id']) && $tipo === 'materias',
                fn (Builder $q) => $q->where(fn (Builder $byLevel) => $byLevel
                    ->whereNull('nivel_id')->orWhere('nivel_id', $filters['compatible_nivel_id'])))
            ->when(isset($filters['sede_id']) && in_array($tipo, ['grupos', 'espacios'], true), fn (Builder $q) => $q->where('sede_id', $filters['sede_id']))
            ->when(isset($filters['jornada_id']) && in_array($tipo, ['grupos', 'bloques'], true), fn (Builder $q) => $q->where('jornada_id', $filters['jornada_id']));

        $selected = isset($filters['selected_id']) ? (clone $query)->find($filters['selected_id']) : null;
        $term = trim($filters['search'] ?? '');
        $nameColumn = in_array($tipo, ['docentes', 'estudiantes'], true) ? 'name' : 'nombre';
        $page = $this->paginateAcademic($query->when($term !== '', fn (Builder $q) => $q->where($nameColumn, 'like', '%'.$term.'%'))
            ->orderBy($nameColumn)->orderBy('id'), $request);
        if ($selected && ! $page->getCollection()->contains('id', $selected->id)) {
            $page->getCollection()->push($selected);
        }
        $page->through(fn ($item) => $this->present($item, $tipo));

        return $this->paginatedResponse($page);
    }

    private function opaqueOptions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'opaque' => ['required', 'accepted'],
            'tipo' => ['required', Rule::in(['sedes', 'jornadas', 'niveles', 'grados', 'grupos', 'materias', 'areas', 'bloques', 'espacios', 'docentes', 'estudiantes'])],
            'ano_lectivo_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'selected_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'compatible_nivel_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'compatible_grupo_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'sede_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'jornada_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'nivel_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'grado_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'area_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'ano_lectivo_id' => ['prohibited'], 'selected_id' => ['prohibited'],
            'compatible_nivel_id' => ['prohibited'], 'area_id' => ['prohibited'],
            'nivel_id' => ['prohibited'], 'sede_id' => ['prohibited'], 'jornada_id' => ['prohibited'],
            'disponibles' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $type = $filters['tipo'];
        $user = $request->user();
        $globalUsers = match ($type) {
            'docentes' => $user->can('academico.plan_estudios.gestionar') || $user->can('notas.ver_consolidado_todos'),
            'estudiantes' => $user->can('academico.matriculas.gestionar') || $user->can('notas.ver_consolidado_todos'),
            default => false,
        };
        $permitted = match ($type) {
            'docentes', 'estudiantes' => $globalUsers || $user->can('notas.registrar_materia_asignada')
                || $user->can('notas.ver_propias'),
            'grupos', 'bloques', 'espacios' => $user->can('academico.estructura.gestionar')
                || $user->can('academico.plan_estudios.gestionar') || $user->can('academico.configurar')
                || $user->can('academico.matriculas.gestionar') || $user->can('notas.ver_consolidado_todos')
                || $user->can('notas.registrar_materia_asignada') || $user->can('notas.ver_propias'),
            'materias', 'areas' => $user->can('academico.plan_estudios.gestionar') || $user->can('academico.configurar')
                || $user->can('notas.ver_consolidado_todos') || $user->can('notas.registrar_materia_asignada'),
            default => $user->can('academico.estructura.gestionar')
                || $user->can('academico.plan_estudios.gestionar') || $user->can('academico.configurar'),
        };
        abort_unless($permitted, 403);
        $scopeToOwnAcademic = in_array($type, ['grupos', 'bloques', 'espacios', 'materias', 'areas'], true)
            && ! $user->can('academico.estructura.gestionar')
            && ! $user->can('academico.plan_estudios.gestionar')
            && ! $user->can('academico.configurar') && ! $user->can('notas.ver_consolidado_todos')
            && ! ($type === 'grupos' && $user->can('academico.matriculas.gestionar'));

        if (! in_array($type, ['sedes', 'docentes', 'estudiantes'], true) && empty($filters['ano_lectivo_token'])) {
            throw ValidationException::withMessages(['ano_lectivo_token' => 'Selecciona un año lectivo.']);
        }
        $year = isset($filters['ano_lectivo_token'])
            ? OpaqueUrlToken::find('ano-lectivo', $filters['ano_lectivo_token'], AnoLectivo::query()) : null;
        if (isset($filters['ano_lectivo_token']) && ! $year) {
            throw ValidationException::withMessages(['ano_lectivo_token' => 'El año lectivo no pertenece a este colegio.']);
        }
        $resource = [
            'sedes' => 'sede', 'jornadas' => 'jornada', 'niveles' => 'nivel',
            'grados' => 'grado', 'grupos' => 'grupo', 'materias' => 'materia', 'areas' => 'area',
            'docentes' => 'usuario', 'estudiantes' => 'usuario',
            'bloques' => 'bloque-horario', 'espacios' => 'espacio-fisico',
        ][$type];
        $query = match ($type) {
            'sedes' => Sede::query(),
            'jornadas' => Jornada::where('ano_lectivo_id', $year->id),
            'niveles' => Nivel::where('ano_lectivo_id', $year->id),
            'grados' => Grado::where('ano_lectivo_id', $year->id),
            'grupos' => Grupo::with('grado:id,nombre,nivel_id')->where('ano_lectivo_id', $year->id),
            'materias' => Materia::with('area:id,nombre')->where('ano_lectivo_id', $year->id),
            'areas' => Area::where('ano_lectivo_id', $year->id),
            'docentes' => User::role('docente')->where('status', 'active'),
            'estudiantes' => User::role('estudiante')->where('status', 'active'),
            'bloques' => BloqueHorario::where('ano_lectivo_id', $year->id)->where('es_descanso', false),
            'espacios' => EspacioFisico::where('ano_lectivo_id', $year->id),
        };
        if (in_array($type, ['docentes', 'estudiantes'], true)) {
            if (! $globalUsers) {
                $this->scopeToUser($query, $type, $user, $user->can('notas.registrar_materia_asignada'));
            }
            if ($type === 'estudiantes' && ! empty($filters['disponibles']) && $year) {
                $query->whereNotIn('id', Matricula::where('ano_lectivo_id', $year->id)
                    ->where('estado', 'activa')->select('estudiante_id'));
            }
        } else {
            $query->where('estado', match ($type) {
                'sedes', 'jornadas' => 'activa', 'espacios' => EspacioFisico::ESTADO_DISPONIBLE,
                default => 'activo',
            });
            if ($scopeToOwnAcademic) {
                $this->scopeToUser($query, $type, $user, $user->can('notas.registrar_materia_asignada'));
            }
        }

        foreach ([
            'sede_token' => ['sede', Sede::class, 'sede_id', ['jornadas', 'grupos', 'espacios']],
            'jornada_token' => ['jornada', Jornada::class, 'jornada_id', ['grupos', 'bloques']],
            'nivel_token' => ['nivel', Nivel::class, 'nivel_id', ['grados', 'materias']],
            'grado_token' => ['grado', Grado::class, 'grado_id', ['grupos']],
            'area_token' => ['area', Area::class, 'area_id', ['materias']],
        ] as $field => [$parentResource, $parentClass, $column, $allowedTypes]) {
            if (! isset($filters[$field])) {
                continue;
            }
            abort_unless(in_array($type, $allowedTypes, true), 422);
            $parent = OpaqueUrlToken::find($parentResource, $filters[$field], $parentClass::query()
                ->when($parentResource !== 'sede', fn (Builder $q) => $q->where('ano_lectivo_id', $year->id)));
            if (! $parent) {
                throw ValidationException::withMessages([$field => 'La selección no pertenece a este año lectivo.']);
            }
            $query->where($column, $parent->id);
        }

        if (isset($filters['compatible_nivel_token'])) {
            abort_unless($type === 'materias', 422);
            $level = OpaqueUrlToken::find('nivel', $filters['compatible_nivel_token'],
                Nivel::where('ano_lectivo_id', $year->id));
            if (! $level) {
                throw ValidationException::withMessages(['compatible_nivel_token' => 'El nivel no pertenece a este año lectivo.']);
            }
            $query->where(fn (Builder $byLevel) => $byLevel->whereNull('nivel_id')->orWhere('nivel_id', $level->id));
        }

        if (isset($filters['compatible_grupo_token'])) {
            abort_unless($type === 'materias', 422);
            $groupQuery = Grupo::with('grado')->where('ano_lectivo_id', $year->id)->where('estado', 'activo');
            if ($scopeToOwnAcademic) {
                $this->scopeToUser($groupQuery, 'grupos', $user, $user->can('notas.registrar_materia_asignada'));
            }
            $group = OpaqueUrlToken::find('grupo', $filters['compatible_grupo_token'],
                $groupQuery);
            abort_unless($group, 404);
            \App\Services\GroupSubjectScope::apply($query, $group);
        }

        $selected = isset($filters['selected_token'])
            ? OpaqueUrlToken::find($resource, $filters['selected_token'], clone $query) : null;
        if (isset($filters['selected_token']) && ! $selected) {
            throw ValidationException::withMessages(['selected_token' => 'La selección no pertenece a este año lectivo.']);
        }
        $term = trim($filters['search'] ?? '');
        $nameColumn = in_array($type, ['docentes', 'estudiantes'], true) ? 'name' : 'nombre';
        $page = $this->paginateAcademic($query->when($term !== '', fn (Builder $q) => $q->where($nameColumn, 'like', '%'.$term.'%'))
            ->orderBy($nameColumn)->orderBy('id'), $request);
        if ($selected && ! $page->getCollection()->contains('id', $selected->id)) {
            $page->getCollection()->push($selected);
        }
        $page->through(fn ($item) => $this->presentOpaque($item, $type));

        return $this->paginatedResponse($page);
    }

    private function presentOpaque(\Illuminate\Database\Eloquent\Model $item, string $type): array
    {
        return match ($type) {
            'sedes' => [
                'url_token' => OpaqueUrlToken::for('sede', $item->id),
                'nombre' => $item->nombre, 'estado' => $item->estado,
            ],
            'jornadas' => [
                'url_token' => OpaqueUrlToken::for('jornada', $item->id),
                'nombre' => $item->nombre,
                'sede_token' => OpaqueUrlToken::for('sede', $item->sede_id),
                'estado' => $item->estado,
            ],
            'niveles' => [
                'url_token' => OpaqueUrlToken::for('nivel', $item->id),
                'nombre' => $item->nombre, 'nivel_educativo' => $item->nivel_educativo,
                'estado' => $item->estado,
            ],
            'grados' => [
                'url_token' => OpaqueUrlToken::for('grado', $item->id),
                'nombre' => $item->nombre,
                'nivel_token' => $item->nivel_id ? OpaqueUrlToken::for('nivel', $item->nivel_id) : null,
                'estado' => $item->estado,
            ],
            'materias' => [
                'url_token' => OpaqueUrlToken::for('materia', $item->id),
                'nombre' => $item->nombre,
                'nivel_token' => $item->nivel_id ? OpaqueUrlToken::for('nivel', $item->nivel_id) : null,
                'area_token' => $item->area_id ? OpaqueUrlToken::for('area', $item->area_id) : null,
                'area' => $item->area ? [
                    'url_token' => OpaqueUrlToken::for('area', $item->area->id),
                    'nombre' => $item->area->nombre,
                ] : null,
                'estado' => $item->estado,
            ],
            'grupos' => [
                'url_token' => OpaqueUrlToken::for('grupo', $item->id),
                'nombre' => $item->nombre,
                'grado_token' => OpaqueUrlToken::for('grado', $item->grado_id),
                'jornada_token' => OpaqueUrlToken::for('jornada', $item->jornada_id),
                'sede_token' => OpaqueUrlToken::for('sede', $item->sede_id),
                'sede' => $item->sede ? [
                    'url_token' => OpaqueUrlToken::for('sede', $item->sede->id), 'nombre' => $item->sede->nombre,
                ] : null,
                'jornada' => $item->jornada ? [
                    'url_token' => OpaqueUrlToken::for('jornada', $item->jornada->id), 'nombre' => $item->jornada->nombre,
                    'hora_inicio' => $item->jornada->hora_inicio, 'hora_fin' => $item->jornada->hora_fin,
                ] : null,
                'grado' => $item->grado ? [
                    'url_token' => OpaqueUrlToken::for('grado', $item->grado->id),
                    'nombre' => $item->grado->nombre,
                    'nivel_token' => OpaqueUrlToken::for('nivel', $item->grado->nivel_id),
                ] : null,
                'estado' => $item->estado,
            ],
            'areas' => ['url_token' => OpaqueUrlToken::for('area', $item->id), 'nombre' => $item->nombre],
            'docentes', 'estudiantes' => ['url_token' => OpaqueUrlToken::for('usuario', $item->id), 'name' => $item->name],
            'bloques' => ['url_token' => OpaqueUrlToken::for('bloque-horario', $item->id),
                'nombre' => $item->nombre, 'jornada_token' => OpaqueUrlToken::for('jornada', $item->jornada_id),
                'hora_inicio' => $item->hora_inicio, 'hora_fin' => $item->hora_fin, 'estado' => $item->estado],
            'espacios' => ['url_token' => OpaqueUrlToken::for('espacio-fisico', $item->id),
                'nombre' => $item->nombre, 'sede_token' => $item->sede_id ? OpaqueUrlToken::for('sede', $item->sede_id) : null,
                'tipo' => $item->tipo, 'capacidad' => $item->capacidad, 'estado' => $item->estado],
        };
    }

    private function scopeToUser(Builder $query, string $tipo, User $user, bool $teacher): void
    {
        $assignments = $teacher
            ? AsignacionDocente::where('docente_id', $user->id)
            : AsignacionDocente::whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)
                ->where('estado', 'activa')->select('grupo_id'));
        match ($tipo) {
            'grupos' => $query->whereIn('id', (clone $assignments)->select('grupo_id')),
            'materias' => $query->whereIn('id', (clone $assignments)->select('materia_id')),
            'docentes' => $query->whereKey($teacher ? $user->id : -1),
            'grados' => $query->whereIn('id', Grupo::whereIn('id', (clone $assignments)->select('grupo_id'))->select('grado_id')),
            'areas' => $query->whereIn('id', Materia::whereIn('id', (clone $assignments)->select('materia_id'))->select('area_id')),
            'bloques' => $query->whereIn('jornada_id', Grupo::whereIn('id', (clone $assignments)->select('grupo_id'))->select('jornada_id')),
            'espacios' => $query->whereIn('sede_id', Grupo::whereIn('id', (clone $assignments)->select('grupo_id'))->select('sede_id')),
            'estudiantes' => $teacher
                ? $query->whereIn('id', Matricula::whereIn('grupo_id', (clone $assignments)->select('grupo_id'))->select('estudiante_id'))
                : $query->whereKey($user->id),
        };
    }

    private function present(\Illuminate\Database\Eloquent\Model $item, string $tipo): array
    {
        $data = $item->toArray();
        return match ($tipo) {
            'grupos' => [...$data, 'url_token' => OpaqueUrlToken::for('grupo', $item->id)],
            'docentes' => ['id' => $item->id, 'name' => $item->name,
                'url_token' => OpaqueUrlToken::for('docente', $item->id)],
            'espacios' => [...$data, 'url_token' => OpaqueUrlToken::for('espacio-fisico', $item->id)],
            'estudiantes' => ['id' => $item->id, 'name' => $item->name],
            default => $data,
        };
    }
}
