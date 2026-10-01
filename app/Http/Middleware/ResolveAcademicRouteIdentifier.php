<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Evento;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Matricula;
use App\Models\Academico\Materia;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\ModeloPedagogico;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\Academico\Sede;
use App\Models\Academico\SesionHorario;
use App\Support\OpaqueUrlToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an opaque, tenant-bound selector for one academic route parameter.
 * Numeric selectors remain accepted until the associated frontend module is migrated.
 */
final class ResolveAcademicRouteIdentifier
{
    private const MODELS = [
        'actividad-evaluacion' => ActividadEvaluacion::class,
        'ano-lectivo' => AnoLectivo::class,
        'area' => Area::class,
        'asignacion-docente' => AsignacionDocente::class,
        'bloque-horario' => BloqueHorario::class,
        'componente-evaluacion' => ComponenteEvaluacion::class,
        'escala-valorativa' => EscalaValorativa::class,
        'espacio-fisico' => EspacioFisico::class,
        'evento' => Evento::class,
        'grado' => Grado::class,
        'grupo' => Grupo::class,
        'jornada' => Jornada::class,
        'matricula' => Matricula::class,
        'materia' => Materia::class,
        'metodo-aprobacion' => MetodoAprobacion::class,
        'modelo-pedagogico' => ModeloPedagogico::class,
        'nivel' => Nivel::class,
        'periodo' => Periodo::class,
        'recuperacion-academica' => RecuperacionAcademica::class,
        'sede' => Sede::class,
        'sesion-horario' => SesionHorario::class,
    ];

    public function handle(Request $request, Closure $next, string $parameter, string $resource): Response
    {
        $value = $request->route($parameter);
        if ($value === null) {
            return $next($request);
        }
        if (ctype_digit((string) $value)) {
            abort_if($request->boolean('opaque'), 404);

            return $next($request);
        }

        $modelClass = self::MODELS[$resource] ?? null;
        abort_unless($modelClass !== null, 500, 'Recurso académico no configurado.');
        $model = OpaqueUrlToken::find($resource, $value, $modelClass::query());
        abort_unless($model !== null, 404);
        $request->route()->setParameter($parameter, (string) $model->getKey());

        return $next($request);
    }
}
