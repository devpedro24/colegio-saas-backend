<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Opciones de formularios académicos; nunca envía catálogos completos por defecto. */
class AcademicOptionsController extends Controller
{
    use PaginatesRequests;

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tipo' => ['required', Rule::in(['areas', 'materias', 'grados', 'grupos', 'docentes', 'bloques', 'espacios', 'estudiantes'])],
            'ano_lectivo_id' => ['nullable', 'integer', 'exists:anos_lectivos,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'integer', 'min:1'],
            'area_id' => ['nullable', 'integer'], 'nivel_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'], 'jornada_id' => ['nullable', 'integer'],
            'disponibles' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $manage = $user->hasRole('rector') || $user->esSuperadminPlataforma()
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
            ->when(isset($filters['sede_id']) && in_array($tipo, ['grupos', 'espacios'], true), fn (Builder $q) => $q->where('sede_id', $filters['sede_id']))
            ->when(isset($filters['jornada_id']) && in_array($tipo, ['grupos', 'bloques'], true), fn (Builder $q) => $q->where('jornada_id', $filters['jornada_id']));

        $selected = isset($filters['selected_id']) ? (clone $query)->find($filters['selected_id']) : null;
        $term = trim($filters['search'] ?? '');
        $nameColumn = in_array($tipo, ['docentes', 'estudiantes'], true) ? 'name' : 'nombre';
        $page = $query->when($term !== '', fn (Builder $q) => $q->where($nameColumn, 'like', '%'.$term.'%'))
            ->orderBy($nameColumn)->orderBy('id')
            ->paginate($this->resolvePerPage($request), ['*'], 'page', $this->resolvePage($request));
        if ($selected && ! $page->getCollection()->contains('id', $selected->id)) {
            $page->getCollection()->push($selected);
        }
        $page->through(fn ($item) => $this->present($item, $tipo));

        return $this->paginatedResponse($page);
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
