<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Resolves tenant-scoped public selectors into private FK values for legacy model services. */
final class ResolveAcademicInputIdentifiers
{
    private const INPUTS = [
        'ano_lectivo_token' => ['ano_lectivo_id', 'ano-lectivo', AnoLectivo::class],
        'sede_token' => ['sede_id', 'sede', Sede::class],
        'jornada_token' => ['jornada_id', 'jornada', Jornada::class],
        'nivel_token' => ['nivel_id', 'nivel', Nivel::class],
        'grado_token' => ['grado_id', 'grado', Grado::class],
        'grupo_token' => ['grupo_id', 'grupo', Grupo::class],
        'area_token' => ['area_id', 'area', Area::class],
        'materia_token' => ['materia_id', 'materia', Materia::class],
        'bloque_horario_token' => ['bloque_horario_id', 'bloque-horario', BloqueHorario::class],
        'espacio_fisico_token' => ['espacio_fisico_id', 'espacio-fisico', EspacioFisico::class],
        'asignacion_token' => ['asignacion_id', 'asignacion-docente', AsignacionDocente::class],
        'periodo_token' => ['periodo_id', 'periodo', Periodo::class],
        'matricula_token' => ['matricula_id', 'matricula', Matricula::class],
        'componente_token' => ['componente_id', 'componente-evaluacion', ComponenteEvaluacion::class],
        'actividad_token' => ['actividad_id', 'actividad-evaluacion', ActividadEvaluacion::class],
        'docente_token' => ['docente_id', 'usuario', User::class],
        'estudiante_token' => ['estudiante_id', 'usuario', User::class],
        'selected_grupo_token' => ['selected_grupo_id', 'grupo', Grupo::class],
        'selected_materia_token' => ['selected_materia_id', 'materia', Materia::class],
        'selected_area_token' => ['selected_area_id', 'area', Area::class],
        'selected_docente_token' => ['selected_docente_id', 'usuario', User::class],
        'selected_bloque_token' => ['selected_bloque_id', 'bloque-horario', BloqueHorario::class],
        'selected_espacio_token' => ['selected_espacio_id', 'espacio-fisico', EspacioFisico::class],
        'espacio_token' => ['espacio_fisico_id', 'espacio-fisico', EspacioFisico::class],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->boolean('opaque')) {
            foreach (array_keys($request->all()) as $field) {
                if ($field === 'id' || str_ends_with($field, '_id') || str_ends_with($field, '_ids')) {
                    throw ValidationException::withMessages([$field => 'Usa el selector público correspondiente.']);
                }
            }
        }

        foreach (self::INPUTS as $tokenField => [$idField, $resource, $modelClass]) {
            $token = $request->input($tokenField);
            if ($token === null || $token === '') {
                continue;
            }
            $model = OpaqueUrlToken::find($resource, $token, $modelClass::query());
            if ($model === null) {
                throw ValidationException::withMessages([$tokenField => 'El selector no pertenece a este colegio.']);
            }
            $legacyId = $request->input($idField);
            if ($legacyId !== null && (string) $legacyId !== (string) $model->getKey()) {
                throw ValidationException::withMessages([$tokenField => 'Los selectores del recurso no coinciden.']);
            }
            $request->merge([$idField => $model->getKey()]);
            if ($request->query->has($tokenField)) {
                $request->query->set($idField, $model->getKey());
            }
        }

        if ($request->exists('grupo_tokens')) {
            $tokens = $request->input('grupo_tokens');
            if (! is_array($tokens) || count($tokens) > 100
                || count(array_filter($tokens, fn ($token) => OpaqueUrlToken::valid($token))) !== count($tokens)
                || count($tokens) !== count(array_unique($tokens))) {
                throw ValidationException::withMessages(['grupo_tokens' => 'Selecciona grupos válidos y sin duplicados.']);
            }
            $remaining = array_fill_keys($tokens, true);
            $matches = [];
            if ($remaining !== []) {
                foreach (Grupo::query()->cursor() as $group) {
                    $token = OpaqueUrlToken::for('grupo', $group->id);
                    if (isset($remaining[$token])) {
                        $matches[$token] = $group->id;
                        unset($remaining[$token]);
                        if ($remaining === []) {
                            break;
                        }
                    }
                }
            }
            if ($remaining !== []) {
                throw ValidationException::withMessages(['grupo_tokens' => 'Un grupo no pertenece a este colegio.']);
            }
            $ids = array_map(fn ($token) => $matches[$token], $tokens);
            $request->merge(['grupo_ids' => $ids]);
        }

        if ($request->exists('notas') && $request->boolean('opaque')) {
            $notes = $request->input('notas');
            if (! is_array($notes) || count($notes) > 1000) {
                throw ValidationException::withMessages(['notas' => 'Envía hasta 1000 notas válidas.']);
            }
            foreach (['actividad' => ActividadEvaluacion::class, 'matricula' => Matricula::class] as $name => $modelClass) {
                $resource = $name === 'actividad' ? 'actividad-evaluacion' : $name;
                $field = $name.'_token';
                $idField = $name.'_id';
                $remaining = [];
                foreach ($notes as $note) {
                    if (! is_array($note) || array_key_exists($idField, $note) || ! OpaqueUrlToken::valid($note[$field] ?? null)) {
                        throw ValidationException::withMessages(['notas' => 'Cada nota requiere selectores públicos de actividad y matrícula.']);
                    }
                    $remaining[$note[$field]] = true;
                }
                $matches = [];
                if ($remaining !== []) {
                    foreach ($modelClass::query()->cursor() as $model) {
                        $token = OpaqueUrlToken::for($resource, $model->getKey());
                        if (isset($remaining[$token])) {
                            $matches[$token] = $model->getKey();
                            unset($remaining[$token]);
                            if ($remaining === []) {
                                break;
                            }
                        }
                    }
                }
                if ($remaining !== []) {
                    throw ValidationException::withMessages(['notas' => 'Una nota referencia un recurso ajeno o inexistente.']);
                }
                foreach ($notes as &$note) {
                    $note[$idField] = $matches[$note[$field]];
                }
                unset($note);
            }
            $request->merge(['notas' => $notes]);
        }

        return $next($request);
    }
}
