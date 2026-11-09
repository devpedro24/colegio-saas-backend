<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\AsistenciaController;
use App\Http\Controllers\Api\Academico\AsistenciaCorreccionController;
use App\Http\Controllers\Api\Academico\AulaArchivoController;
use App\Http\Controllers\Api\Academico\AulaController;
use App\Http\Controllers\Api\Academico\AulaCuestionarioController;
use App\Http\Controllers\Api\Academico\AulaMedioController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\DirectorGrupoController;
use App\Http\Controllers\Api\Academico\EscalaOpcionController;
use App\Http\Controllers\Api\Academico\EscalaValorativaController;
use App\Http\Controllers\Api\Academico\EspacioFisicoController;
use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Controllers\Api\Academico\EventoController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\GrupoController;
use App\Http\Controllers\Api\Academico\HorarioController;
use App\Http\Controllers\Api\Academico\IndicadorController;
use App\Http\Controllers\Api\Academico\IndicatorPolicyController;
use App\Http\Controllers\Api\Academico\IndicatorSheetController;
use App\Http\Controllers\Api\Academico\JornadaController;
use App\Http\Controllers\Api\Academico\ManualAnnualResultController;
use App\Http\Controllers\Api\Academico\ManualAreaResultController;
use App\Http\Controllers\Api\Academico\MetodoAprobacionController;
use App\Http\Controllers\Api\Academico\ModeloPedagogicoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Controllers\Api\Academico\PlanEstudiosController;
use App\Http\Controllers\Api\Academico\PlanillaActividadesController;
use App\Http\Controllers\Api\Academico\PreinformeController;
use App\Http\Controllers\Api\Academico\PromocionAcademicaController;
use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Api\Academico\SieeController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Server-confirmed action results, private to the acting account and tenant. */
final class RecordAcademicNotifications
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $actor = $request->user();
        if ($request->isMethodSafe() || ! tenancy()->initialized || ! $actor) {
            return $response;
        }
        [$controller, $action] = array_pad(explode('@', $request->route()?->getActionName() ?? ''), 2, '');
        // Normalize the shared study-plan controllers into explicit user-facing events.
        if ($controller === EventoController::class) {
            [$module, $action] = match ($action) {
                'guardar' => ['calendar.event', $response->getStatusCode() === 201 ? 'store' : 'update'],
                'publicar' => ['calendar.event', 'publish'], 'destroy' => ['calendar.event', 'destroy'],
                'archivo' => ['calendar.attachment', 'upload'], 'configurar' => ['calendar.configuration', 'save'],
                'reintentarCorreos' => ['calendar.mail', 'retry'], default => [null, ''],
            };
        } elseif ($controller === AsistenciaController::class) {
            [$module, $action] = match ($action) {
                'save' => ['attendance.sheet', 'save'], 'savePolicy' => ['attendance.policy', 'save'],
                default => [null, ''],
            };
        } elseif ($controller === AsistenciaCorreccionController::class) {
            [$module, $action] = match ($action) {
                'store' => ['attendance.request', 'store'],
                'review' => ['attendance.request', $request->input('decision') === 'rechazar' ? 'reject' : 'forward'],
                'resolve' => ['attendance.request', $request->input('decision') === 'rechazar' ? 'reject' : 'approve'],
                default => [null, ''],
            };
        } elseif (in_array($controller, [AulaController::class, AulaArchivoController::class,
            AulaCuestionarioController::class, AulaMedioController::class], true)) {
            [$module, $action] = $this->aulaEvent($controller, $action, $request);
            if ($action === 'update' && $request->attributes->get('aula.visibility_changed')) {
                $action = 'visibility';
            }
        } elseif ($controller === PlanEstudiosController::class) {
            $module = str_ends_with($action, 'Area') ? 'plan.area' : 'plan.materia';
            $action = match ($action) {
                'storeArea', 'storeMateria' => 'store',
                'updateArea', 'updateMateria' => 'update',
                'destroyArea', 'destroyMateria' => 'destroy',
                default => '',
            };
        } elseif ($controller === HorarioController::class) {
            $module = in_array($action, ['asignar', 'asignarGrupo', 'editarAsignacion', 'desasignar'], true)
                ? 'plan.assignment' : 'plan.schedule';
            $action = match ($action) {
                'asignar' => $response->getStatusCode() === 200 ? 'update' : 'store',
                'asignarGrupo' => 'assignGroup',
                'editarAsignacion' => 'update',
                'desasignar', 'eliminar' => 'destroy',
                'guardar' => $request->isMethod('PUT') ? 'update' : 'store',
                default => '',
            };
        } elseif ($controller === SieeController::class) {
            $module = $action === 'update' ? 'siee.configuration' : 'siee.curriculum';
            $action = match ($action) {
                'update' => 'update',
                'curriculo' => 'save',
                'curriculoMasivo' => 'saveBulk',
                default => '',
            };
        } elseif ($controller === IndicatorSheetController::class) {
            $module = 'indicators.sheet';
            $action = match ($action) {
                'save' => 'save', 'delete' => 'destroy', default => '',
            };
        } elseif ($controller === IndicadorController::class) {
            [$module, $action] = match ($action) {
                'crear' => ['indicators.base', 'store'],
                'crearGrupo' => ['indicators.group', 'store'],
                'revisar' => ['indicators.base', 'update'],
                'vincular' => ['indicators.binding', 'store'],
                'actualizarVinculo', 'editarTextoGrado' => ['indicators.binding', 'update'],
                'variante' => ['indicators.variant', 'save'],
                default => [null, ''],
            };
        } elseif ($controller === PlanillaActividadesController::class && $action === 'save') {
            $module = 'gradebook.activity';
            $action = match ($request->input('operacion')) {
                'crear' => 'store', 'editar' => 'update', 'eliminar' => 'destroy',
                'editar_lote' => 'saveBulk', 'modo' => 'policy', default => '',
            };
        } elseif ($controller === EvaluacionController::class) {
            [$module, $action] = match ($action) {
                'notas' => ['gradebook.grades', 'save'],
                'resultadoManual' => ['gradebook.manualSubject', 'save'],
                'resultadoManualComponente' => ['gradebook.manualComponent', 'save'],
                'componente' => ['gradebook.component', $request->isMethod('POST') ? 'store' : 'update'],
                'actividad' => ['gradebook.activity', $request->isMethod('POST') ? 'store' : 'update'],
                // Calculation previews never write grades or notification records.
                default => [null, ''],
            };
        } else {
            $module = match ($controller) {
                AnoLectivoController::class => 'year',
                SedeController::class => 'structure.sede',
                PeriodoController::class => 'period',
                PromocionAcademicaController::class => 'promotion',
                JornadaController::class => 'structure.jornada',
                NivelController::class => 'structure.nivel',
                GradoController::class => 'structure.grado',
                GrupoController::class => 'structure.grupo',
                BloqueHorarioController::class => 'structure.bloque',
                EspacioFisicoController::class => 'structure.espacio',
                DirectorGrupoController::class => 'structure.director',
                EscalaValorativaController::class => 'parameters.scale',
                EscalaOpcionController::class => 'parameters.options',
                MetodoAprobacionController::class => 'parameters.approval',
                ModeloPedagogicoController::class => 'parameters.model',
                PreinformeController::class => 'preinformes',
                IndicatorPolicyController::class => 'indicators.policy',
                ManualAreaResultController::class => 'gradebook.manualArea',
                ManualAnnualResultController::class => 'gradebook.manualAnnual',
                default => null,
            };
        }
        if (in_array($module, ['gradebook.manualSubject', 'gradebook.manualComponent', 'gradebook.manualArea', 'gradebook.manualAnnual'], true)) {
            $action = $request->input('valor') === null && ! $request->filled('escala_opcion_token') ? 'destroy' : 'save';
        }
        if (str_starts_with($module ?? '', 'parameters.') && $action === 'store' && $response->getStatusCode() === 200) {
            $action = 'update';
        }
        if (! $module || ! in_array($action, ['store', 'update', 'destroy', 'duplicar', 'copiarConfiguracion',
            'iniciar', 'cerrar', 'reabrir', 'abrir', 'policy', 'approve', 'save', 'assignGroup', 'upload', 'saveBulk'], true)
            && ! (str_starts_with($module, 'aula.') && in_array($action,
                ['restore', 'archive', 'link', 'grade', 'review', 'retry', 'reactivate', 'send', 'start', 'finish', 'visibility', 'saveAnswers'], true))
            && ! (str_starts_with($module, 'attendance.') && in_array($action, ['reject', 'forward'], true))
            && ! (str_starts_with($module, 'calendar.') && in_array($action, ['publish', 'retry'], true))) {
            return $response;
        }
        $status = $response->getStatusCode();
        $body = json_decode($response->getContent() ?: '{}', true) ?? [];
        $failed = $status >= 400;
        // Never retain request bodies, identities, credentials or internal exception details.
        $message = $failed && in_array($status, [409, 422], true)
            ? (collect($body['errors'] ?? [])->flatten()->first() ?? $body['message'] ?? null)
            : null;
        $subject = ! $failed ? ($body['data']['nombre'] ?? $request->input('nombre', '')) : '';
        if (str_starts_with($module, 'attendance.')) {
            $subject = '';
            $message = null; // Reasons and student identities stay in the authorized attendance workflow.
        }
        if (str_starts_with($module, 'aula.') || str_starts_with($module, 'calendar.')) {
            $subject = $failed ? '' : ($body['data']['titulo'] ?? $request->input('titulo', ''));
            // The client retains its specific validation toast; persistent feeds retain no student data.
            $message = null;
        }
        if (! $failed && in_array($module, ['plan.assignment', 'plan.schedule'], true)) {
            $subject = implode(' · ', array_filter([
                $body['data']['materia']['nombre'] ?? null, $body['data']['grupo']['nombre'] ?? null,
            ]));
        }
        try {
            $token = Str::random(24);
            DB::table('user_notification_records')->insert([
                'url_token' => $token, 'user_id' => $actor->getKey(),
                'variant' => $failed ? 'danger' : ($action === 'destroy' ? 'deleted'
                    : (in_array($action, ['update', 'policy', 'copiarConfiguracion', 'save', 'assignGroup', 'upload', 'saveBulk',
                        'visibility', 'archive', 'link', 'grade', 'review', 'retry', 'reactivate', 'saveAnswers'], true) ? 'updated' : 'success')),
                'event' => $module.'.'.$action,
                'subject' => Str::limit(strip_tags((string) $subject), 120, ''),
                'message' => $message === null ? null : Str::limit(strip_tags((string) $message), 1000, ''),
                'created_at' => now(),
            ]);
            $response->headers->set('X-Notification-Record', $token);
        } catch (Throwable $exception) {
            // A failed notification write must not make a committed action look unsuccessful.
            report($exception);
        }

        return $response;
    }

    private function aulaEvent(string $controller, string $action, Request $request): array
    {
        if ($controller === AulaController::class) {
            return match ($action) {
                'crearSeccion' => ['aula.section', 'store'], 'editarSeccion' => ['aula.section', 'update'],
                'eliminarSeccion' => ['aula.section', 'destroy'], 'restaurarSeccion' => ['aula.section', 'restore'],
                'crearRecurso' => ['aula.resource', 'store'], 'editarRecurso' => ['aula.resource', 'update'],
                'eliminarRecurso' => ['aula.resource', 'destroy'], 'restaurarRecurso' => ['aula.resource', 'restore'],
                'archivarRecurso' => ['aula.resource', 'archive'], 'vincularPlanilla' => ['aula.resource', 'link'],
                'guardarConfiguracion' => ['aula.configuration', 'save'],
                'entregar' => ['aula.submission', 'send'], 'calificar' => ['aula.submission', 'grade'],
                'revisarEntrega' => ['aula.submission', 'review'], default => [null, ''],
            };
        }
        if ($controller === AulaCuestionarioController::class) {
            return match ($action) {
                'guardarPreguntas' => ['aula.quiz', 'save'], 'calificar' => ['aula.quiz', 'grade'],
                'guardarRespuestas' => $request->boolean('registrar_accion') ? ['aula.quiz', 'saveAnswers'] : [null, ''],
                'revisarPreguntas' => ['aula.quiz', 'review'], 'reintentarPlanilla' => ['aula.quiz', 'retry'],
                'reactivar' => ['aula.quiz', 'reactivate'], 'iniciar' => ['aula.quiz', 'start'],
                'finalizar' => ['aula.quiz', 'finish'], default => [null, ''],
            };
        }

        return match ($action) {
            'subirPortada', 'subirRecurso', 'subirEntrega', 'subirPregunta', 'subirRespuesta' => ['aula.attachment', 'upload'],
            'quitarAdjunto', 'quitarPregunta' => ['aula.attachment', 'destroy'], default => [null, ''],
        };
    }
}
