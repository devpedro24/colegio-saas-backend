<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaPregunta;
use App\Models\Academico\AulaPreguntaMedio;
use App\Models\Academico\AulaRespuestaMedio;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\Calificacion;
use App\Models\Academico\EscalaOpcion;
use App\Services\AulaAccess;
use App\Services\AulaContentPolicy;
use App\Services\AulaGradebookService;
use App\Services\EscalaVisualService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

final class AulaCuestionarioController extends Controller
{
    public function __construct(private readonly AulaAccess $access) {}

    public function guardarPreguntas(Request $request, string $token): JsonResponse
    {
        $resource = $this->quiz($token);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.gestionar');
        $this->access->content($request->user(), $resource->seccion->aula, 'editar', true);
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        abort_if(AulaIntento::where('recurso_id', $resource->id)->exists(), 422,
            'El cuestionario ya tiene intentos. No cambies sus respuestas históricas.');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'preguntas' => ['required', 'array', 'min:1', 'max:100'],
            'preguntas.*.token' => ['nullable', 'string', 'size:24'],
            'preguntas.*.tipo' => ['required', Rule::in(['unica', 'multiple', 'booleano', 'correspondencia', 'orden', 'abierta', 'audio', 'video'])],
            'preguntas.*.enunciado' => ['required', 'string', 'max:10000'],
            'preguntas.*.opciones' => ['nullable', 'array', 'max:30'],
            'preguntas.*.opciones.*' => ['string', 'max:1000'],
            'preguntas.*.respuesta_correcta' => ['nullable', 'array'],
            'preguntas.*.puntos' => ['required', 'numeric', 'min:0.001', 'max:100'],
            'preguntas.*.puntajes_opciones' => ['nullable', 'array'],
            'preguntas.*.rubrica' => ['nullable', 'array', 'max:15'],
            'preguntas.*.rubrica.*.nombre' => ['required_with:preguntas.*.rubrica', 'string', 'max:160'],
            'preguntas.*.rubrica.*.puntos' => ['required_with:preguntas.*.rubrica', 'numeric', 'min:0.001', 'max:100'],
        ]);
        foreach ($data['preguntas'] as &$question) {
            $options = array_map('trim', $question['opciones'] ?? []);
            $answer = $question['respuesta_correcta']['valor'] ?? null;
            if (in_array($question['tipo'], ['abierta', 'audio', 'video'], true)) {
                $question['opciones'] = [];
                $question['respuesta_correcta'] = null;
                $question['puntajes_opciones'] = null;
                $rubric = $question['rubrica'] ?? [];
                abort_if(collect($rubric)->sum(fn ($item) => (float) $item['puntos']) > (float) $question['puntos'] + 0.00001,
                    422, 'Los criterios de la rúbrica no pueden superar los puntos de la pregunta.');
                continue;
            }
            abort_if(! empty($question['rubrica']), 422, 'La rúbrica corresponde a una respuesta abierta, de audio o de video.');
            $question['rubrica'] = null;
            if ($question['tipo'] === 'booleano') {
                $options = ['Verdadero', 'Falso'];
            } else {
                abort_if(count($options) < 2 || in_array('', $options, true)
                    || count(array_unique($options)) !== count($options), 422,
                    'Cada pregunta objetiva necesita al menos dos opciones distintas y completas.');
            }
            if (in_array($question['tipo'], ['unica', 'booleano'], true)) {
                abort_unless(is_string($answer) && in_array(trim($answer), $options, true), 422,
                    'Selecciona una respuesta correcta incluida en las opciones.');
                $answer = trim($answer);
            } else {
                abort_unless(is_array($answer) && count($answer) > 0
                    && collect($answer)->every(fn ($value) => is_string($value)
                        && trim($value) !== '' && mb_strlen($value) <= 1000), 422,
                    'Completa la respuesta correcta de la pregunta.');
                $answer = array_map('trim', $answer);
                abort_if(count(array_unique($answer)) !== count($answer), 422,
                    'La respuesta correcta no puede repetir elementos.');
                if ($question['tipo'] === 'multiple') {
                    abort_if(count(array_diff($answer, $options)) > 0, 422,
                        'Las respuestas correctas deben pertenecer a las opciones.');
                } else {
                    abort_if(count($answer) !== count($options), 422,
                        'La respuesta correcta debe incluir un elemento por cada opción.');
                    if ($question['tipo'] === 'orden') {
                        abort_if(count(array_diff($answer, $options)) > 0, 422,
                            'El orden correcto debe contener exactamente los elementos propuestos.');
                    }
                }
            }
            $question['opciones'] = $options;
            $question['respuesta_correcta'] = ['valor' => $answer];
            $weights = $question['puntajes_opciones'] ?? null;
            if ($weights !== null) {
                abort_unless(in_array($question['tipo'], ['unica', 'multiple', 'booleano'], true), 422,
                    'Los puntos por opción solo se usan en selección única, múltiple y verdadero/falso.');
                abort_if(array_diff(array_keys($weights), $options) || collect($weights)->contains(
                    fn ($value) => ! is_numeric($value) || (float) $value < 0 || (float) $value > (float) $question['puntos']),
                    422, 'Los puntos por opción deben corresponder a opciones válidas de la pregunta.');
                abort_if($question['tipo'] === 'multiple' && array_sum(array_map('floatval', $weights)) > (float) $question['puntos'] + 0.00001,
                    422, 'La suma de los puntos por opción no puede superar los puntos de la pregunta.');
                $question['puntajes_opciones'] = $weights;
            }
        }
        unset($question);
        DB::transaction(function () use ($resource, $data, $request): void {
            $locked = AulaRecurso::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409,
                'El cuestionario cambió en otra sesión. Recarga antes de guardar preguntas.');
            abort_if(AulaIntento::where('recurso_id', $locked->id)->exists(), 422,
                'El cuestionario ya tiene intentos. No cambies sus respuestas históricas.');
            $kept = [];
            foreach ($data['preguntas'] as $order => $question) {
                $existing = ! empty($question['token']) ? Token::find('aula-pregunta', $question['token'],
                    AulaPregunta::where('recurso_id', $resource->id)) : null;
                abort_if(! empty($question['token']) && ! $existing, 422, 'La pregunta no pertenece a este cuestionario.');
                abort_if($existing && in_array($existing->id, $kept, true), 422, 'Una pregunta aparece dos veces.');
                $fields = ['recurso_id' => $resource->id, 'tipo' => $question['tipo'],
                    'enunciado' => $question['enunciado'], 'opciones' => $question['opciones'] ?? null,
                    'respuesta_correcta' => $question['respuesta_correcta'] ?? null,
                    'puntajes_opciones' => $question['puntajes_opciones'] ?? null, 'rubrica' => $question['rubrica'] ?? null,
                    'puntos' => $question['puntos'], 'orden' => $order];
                if ($existing) {
                    $previousOptions = $existing->opciones ?? [];
                    foreach ($existing->medios()->whereNotNull('opcion_indice')->get() as $medium) {
                        if (($previousOptions[$medium->opcion_indice] ?? null) !== ($fields['opciones'][$medium->opcion_indice] ?? null)) {
                            $medium->delete();
                        }
                    }
                    $existing->update($fields);
                    $kept[] = $existing->id;
                } else {
                    $kept[] = AulaPregunta::create($fields)->id;
                }
            }
            AulaPregunta::where('recurso_id', $resource->id)->whereNotIn('id', $kept)->delete();
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_preguntas', (string) $resource->id, null,
                ['cantidad' => count($data['preguntas'])]);
            $locked->update(['version' => $locked->version + 1]);
        });

        return response()->json(['data' => ['guardado' => true, 'version' => $resource->fresh()->version,
            'preguntas' => $this->questionData($resource->fresh()->preguntas()->with('medios')->get())]]);
    }

    public function iniciar(Request $request, string $token): JsonResponse
    {
        $resource = $this->quiz($token);
        $this->access->readResource($request->user(), $resource);
        abort_unless($request->user()->can('aula.evaluaciones.responder'), 403);
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_if($resource->estado === 'cerrado' || ($resource->disponible_hasta
            && now('UTC')->gt($resource->disponible_hasta)), 422, 'El plazo del cuestionario terminó.');
        $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
        abort_unless($enrollment, 403);
        abort_if($resource->preguntas()->count() === 0, 422, 'El cuestionario todavía no tiene preguntas.');
        $config = $resource->configuracion ?? [];
        $attempt = DB::transaction(function () use ($resource, $enrollment, $config, $request) {
            $latest = AulaIntento::where('recurso_id', $resource->id)->where('matricula_id', $enrollment->id)
                ->orderByDesc('numero')->lockForUpdate()->first();
            abort_if($latest?->estado === 'bloqueado', 423, 'El intento está bloqueado. Espera la reactivación del docente.');
            if ($latest?->estado === 'en_curso' && (! $latest->vence_at || now('UTC')->lt($latest->vence_at))) return $latest;
            if ($latest?->estado === 'en_curso') {
                $latest->update(['estado' => 'tiempo_agotado', 'finalizado_at' => now('UTC'),
                    'version' => $latest->version + 1]);
                AuditLogger::tenant($request->user(), 'UPDATE', 'aula_intento', (string) $latest->id, null,
                    ['estado' => 'tiempo_agotado']);
            }
            abort_if(($latest?->numero ?? 0) >= ($config['intentos'] ?? 1), 422, 'Se agotaron los intentos disponibles.');
            $duration = (int) ($config['duracion_minutos'] ?? 60);
            $expires = now('UTC')->addMinutes($duration);
            if ($resource->disponible_hasta && $resource->disponible_hasta->lt($expires)) $expires = $resource->disponible_hasta;
            $presentation = $this->newPresentation($resource, $config);
            $attempt = AulaIntento::create(['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id,
                'numero' => ($latest?->numero ?? 0) + 1, 'estado' => 'en_curso', 'iniciado_at' => now('UTC'),
                'vence_at' => $expires, 'respuestas' => [], 'pagina_actual' => 1,
                'presentacion' => $presentation, 'version' => 1]);
            AuditLogger::tenant($request->user(), 'CREATE', 'aula_intento', (string) $attempt->id, null,
                ['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id, 'numero' => $attempt->numero]);

            return $attempt;
        });

        return response()->json(['data' => $this->attemptData($attempt, $resource, true)]);
    }

    public function verIntento(Request $request, string $token): JsonResponse
    {
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $resource = $attempt->recurso;
        $this->access->readResource($request->user(), $resource);
        $own = $this->access->enrollment($request->user(), $resource->seccion->aula)?->id === $attempt->matricula_id;
        if (! $own) $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.calificar');
        abort_unless($own || $request->user()->can('aula.evaluaciones.calificar'), 403);

        return response()->json(['data' => $this->attemptData($attempt, $resource, $own)]);
    }

    public function miIntento(Request $request, string $token): JsonResponse
    {
        $resource = $this->quiz($token);
        $this->access->readResource($request->user(), $resource);
        abort_unless($request->user()->can('aula.evaluaciones.responder'), 403);
        $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
        abort_unless($enrollment, 403);
        $attempt = AulaIntento::where('recurso_id', $resource->id)->where('matricula_id', $enrollment->id)
            ->orderByDesc('numero')->first();

        return response()->json(['data' => $attempt ? $this->attemptData($attempt, $resource, true) : null]);
    }

    public function guardarRespuestas(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['respuestas' => ['required', 'array', 'max:100']]);
        $attempt = DB::transaction(function () use ($request, $token, $data) {
            $attempt = Token::find('aula-intento', $token, AulaIntento::query());
            abort_unless($attempt, 404);
            $attempt = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->ownActiveAttempt($request, $attempt);
            $questions = $this->orderedQuestions($attempt, $attempt->recurso);
            $allowed = $questions->map(fn ($question) => Token::for('aula-pregunta', $question->id))->all();
            $previous = $attempt->respuestas ?? [];
            $config = $attempt->recurso->configuracion ?? [];
            $perPage = max(1, (int) ($config['preguntas_por_pagina'] ?? 100));
            foreach ($data['respuestas'] as $key => $answer) {
                abort_unless(in_array($key, $allowed, true) && (is_array($answer) || is_string($answer) || is_bool($answer)), 422,
                    'La respuesta no pertenece a este cuestionario.');
                abort_if(is_string($answer) && mb_strlen($answer) > 10000, 422, 'La respuesta es demasiado larga.');
                abort_if(is_array($answer) && (count($answer) > 30 || collect($answer)->contains(
                    fn ($item) => ! is_string($item) || mb_strlen($item) > 1000)), 422, 'La respuesta no es válida.');
                $index = array_search($key, $allowed, true);
                $question = $questions[$index];
                if (in_array($question->tipo, ['audio', 'video'], true)) {
                    $medium = AulaRespuestaMedio::where('intento_id', $attempt->id)
                        ->where('pregunta_id', $question->id)->first();
                    abort_unless(is_string($answer) && $medium
                        && hash_equals(Token::for('aula-respuesta-medio', $medium->id), $answer), 422,
                        'La respuesta multimedia debe ser un archivo propio de este intento.');
                } elseif ($question->tipo === 'abierta') {
                    abort_unless(is_string($answer), 422, 'La respuesta abierta debe ser texto.');
                } elseif (in_array($question->tipo, ['unica', 'booleano'], true)) {
                    abort_unless(is_string($answer) && in_array($answer, $question->opciones ?? [], true), 422,
                        'Elige una opción válida.');
                } elseif ($question->tipo === 'correspondencia') {
                    $choices = $question->respuesta_correcta['valor'] ?? [];
                    abort_unless(is_array($answer) && count($answer) === count($question->opciones ?? [])
                        && collect($answer)->every(fn ($value) => is_string($value)
                            && ($value === '' || in_array($value, $choices, true)))
                        && count(array_unique(array_filter($answer, fn ($value) => $value !== '')))
                            === count(array_filter($answer, fn ($value) => $value !== '')), 422,
                        'Cada correspondencia debe usar una opción válida sin repetirla.');
                } else {
                    abort_unless(is_array($answer) && collect($answer)->every(fn ($value) =>
                        is_string($value) && in_array($value, $question->opciones ?? [], true))
                        && count(array_unique($answer)) === count($answer)
                        && ($question->tipo !== 'orden' || count($answer) === count($question->opciones ?? [])), 422,
                        'La respuesta no pertenece a las opciones.');
                }
                $answerPage = intdiv($index, $perPage) + 1;
                abort_if($answerPage > $attempt->pagina_actual, 422,
                    'No puedes responder una página que aún no has abierto.');
                abort_if(! ($config['permitir_regresar'] ?? true)
                    && $answerPage < $attempt->pagina_actual
                    && (! array_key_exists($key, $previous) || $previous[$key] !== $answer), 422,
                    'No puedes cambiar una respuesta de una página anterior.');
                abort_if(! ($config['permitir_editar_respuestas'] ?? true)
                    && array_key_exists($key, $previous) && $previous[$key] !== $answer, 422,
                    'Este cuestionario no permite modificar una respuesta ya guardada.');
            }
            foreach ($previous as $key => $answer) {
                abort_if(! array_key_exists($key, $data['respuestas']), 422,
                    'No se pueden borrar respuestas ya guardadas en este intento.');
            }
            $attempt->update(['respuestas' => $data['respuestas'], 'version' => $attempt->version + 1]);

            return $attempt;
        });

        return response()->json(['data' => ['token' => Token::for('aula-intento', $attempt->id), 'version' => $attempt->version]]);
    }

    public function cambiarPagina(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['pagina' => ['required', 'integer', 'min:1']]);
        $attempt = DB::transaction(function () use ($request, $token, $data): AulaIntento {
            $attempt = Token::find('aula-intento', $token, AulaIntento::query());
            abort_unless($attempt, 404);
            $attempt = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->ownActiveAttempt($request, $attempt);
            $config = $attempt->recurso->configuracion ?? [];
            $perPage = max(1, (int) ($config['preguntas_por_pagina'] ?? 100));
            $total = max(1, (int) ceil($attempt->recurso->preguntas()->count() / $perPage));
            $target = (int) $data['pagina'];
            abort_if($target > $total || abs($target - $attempt->pagina_actual) > 1
                || ($target < $attempt->pagina_actual && ! ($config['permitir_regresar'] ?? true)), 422,
                'No se puede abrir esa página del cuestionario.');
            $attempt->update(['pagina_actual' => $target, 'version' => $attempt->version + 1]);

            return $attempt;
        });

        return response()->json(['data' => $this->attemptData($attempt, $attempt->recurso, true)]);
    }

    public function finalizar(Request $request, string $token): JsonResponse
    {
        $attempt = DB::transaction(function () use ($request, $token) {
            $attempt = Token::find('aula-intento', $token, AulaIntento::query());
            abort_unless($attempt, 404);
            $attempt = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->ownActiveAttempt($request, $attempt);
            $minimum = (int) ($attempt->recurso->configuracion['duracion_minima_minutos'] ?? 0);
            abort_if($minimum > 0 && now('UTC')->lt($attempt->iniciado_at->copy()->addMinutes($minimum)), 422,
                "Este cuestionario exige al menos {$minimum} minuto(s) antes de finalizar.");
            $questions = AulaPregunta::where('recurso_id', $attempt->recurso_id)->get();
            $hasOpen = $questions->contains(fn ($question) => in_array($question->tipo, ['abierta', 'audio', 'video'], true));
            $points = BigDecimal::zero();
            $earned = BigDecimal::zero();
            foreach ($questions as $question) {
                $points = $points->plus((string) $question->puntos);
                if (in_array($question->tipo, ['abierta', 'audio', 'video'], true)) continue;
                $given = ($attempt->respuestas ?? [])[Token::for('aula-pregunta', $question->id)] ?? null;
                $earned = $earned->plus($this->objectivePoints($question, $given));
            }
            $grade = null;
            if (! $hasOpen && ! $points->isZero()) {
                $year = AnoLectivo::findOrFail($attempt->recurso->seccion->aula->ano_lectivo_id);
                $config = app(SieeConfiguration::class)->resolve($year);
                $min = BigDecimal::of((string) $config['valor_min']);
                $range = BigDecimal::of((string) $config['valor_max'])->minus($min);
                $grade = (string) $min->plus($earned->dividedBy($points, 8, RoundingMode::HALF_DOWN)->multipliedBy($range))
                    ->toScale(8, RoundingMode::HALF_DOWN);
            }
            $scale = $grade === null ? null : app(EscalaVisualService::class)->forGroup($attempt->recurso->seccion->aula->grupo);
            $choice = $scale ? app(EscalaVisualService::class)->nearestChoice($grade, $scale->opciones) : null;
            $attempt->update(['estado' => $hasOpen ? 'pendiente_revision' : 'finalizado',
                'finalizado_at' => now('UTC'), 'nota' => $grade, 'escala_opcion_id' => $choice?->id,
                'version' => $attempt->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_intento', (string) $attempt->id, null,
                ['estado' => $attempt->estado, 'nota' => $grade]);

            return $attempt;
        });

        $transferred = false;
        if ($attempt->estado === 'finalizado' && $attempt->nota !== null
            && ($attempt->recurso->configuracion['transferencia'] ?? 'confirmar') === 'automatica') {
            try {
                $transferred = app(AulaGradebookService::class)->transferAutomatic($attempt->recurso,
                    $request->user(), $attempt->matricula_id, (string) $attempt->nota);
            } catch (Throwable $error) {
                // La entrega permanece finalizada; una falla de planilla no borra respuestas.
                report($error);
            }
        }

        $officialExists = $attempt->recurso->actividad_id && Calificacion::where('actividad_id', $attempt->recurso->actividad_id)
            ->where('matricula_id', $attempt->matricula_id)->exists();
        $automatic = $attempt->recurso->llevar_planilla
            && ($attempt->recurso->configuracion['transferencia'] ?? 'confirmar') === 'automatica';

        return response()->json(['data' => ['estado' => $attempt->estado,
            'nota' => $attempt->escala_opcion_id ? null : $attempt->nota,
            'valoracion' => $attempt->escala_opcion_id
                ? EscalaOpcionController::present(EscalaOpcion::findOrFail($attempt->escala_opcion_id)) : null,
            'transferida_planilla' => $transferred,
            'estado_planilla' => ! $automatic || $attempt->nota === null ? 'no_aplica'
                : ($transferred ? 'transferida' : ($officialExists ? 'nota_oficial_existente' : 'pendiente'))]]);
    }

    /** Explicit retry after an automatic transfer failed; never replaces an existing official note. */
    public function reintentarPlanilla(Request $request, string $token): JsonResponse
    {
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $resource = $attempt->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.gestionar');
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.planilla.vincular');
        abort_unless($resource->llevar_planilla
            && ($resource->configuracion['transferencia'] ?? 'confirmar') === 'automatica'
            && $attempt->estado === 'finalizado' && $attempt->nota !== null, 422,
            'Este intento no tiene una transferencia automática pendiente.');
        $data = $request->validate(['motivo' => ['required', 'string', 'min:8', 'max:1000']]);
        DB::transaction(function () use ($resource, $attempt, $request, $data): void {
            $activity = $resource->actividad_id
                ? ActividadEvaluacion::findOrFail($resource->actividad_id)
                : app(AulaGradebookService::class)->link($resource, $request->user());
            abort_unless($activity, 422, 'El período aún no está abierto; la nota sigue pendiente.');
            abort_if(Calificacion::where('actividad_id', $activity->id)
                ->where('matricula_id', $attempt->matricula_id)->exists(), 409,
                'Ya existe una nota oficial. No se reemplazó.');
            app(AulaGradebookService::class)->transfer($resource->fresh(), $request->user(),
                $attempt->matricula_id, (string) $attempt->nota, null, $data['motivo']);
        });

        return response()->json(['data' => ['estado_planilla' => 'transferida']]);
    }

    public function incidente(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['tipo' => ['required', Rule::in(['visibilidad', 'pantalla_completa', 'desconexion'])]]);
        $attempt = DB::transaction(function () use ($request, $token, $data) {
            $attempt = Token::find('aula-intento', $token, AulaIntento::query());
            abort_unless($attempt, 404);
            $attempt = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->ownActiveAttempt($request, $attempt);
            abort_unless($attempt->recurso->configuracion['vigilado'] ?? false, 422);
            $previous = DB::table('aula_incidentes')->where('intento_id', $attempt->id)->orderByDesc('id')->first();
            // Un cambio de pestaña suele disparar visibilidad y pantalla completa juntos.
            if ($previous && \Illuminate\Support\Carbon::parse($previous->created_at)->gt(now('UTC')->subSeconds(2))) {
                return $attempt;
            }
            DB::table('aula_incidentes')->insert(['intento_id' => $attempt->id, 'tipo' => $data['tipo'],
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $count = $attempt->incidentes + 1;
            $limit = (int) ($attempt->recurso->configuracion['incidentes_permitidos'] ?? 0);
            $attempt->update(['incidentes' => $count, 'estado' => $count > $limit ? 'bloqueado' : 'en_curso',
                'version' => $attempt->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_incidente', (string) $attempt->id, null,
                ['tipo' => $data['tipo'], 'incidentes' => $count, 'estado' => $attempt->estado]);

            return $attempt;
        });

        return response()->json(['data' => ['estado' => $attempt->estado, 'incidentes' => $attempt->incidentes]]);
    }

    public function reactivar(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'min:8', 'max:1000']]);
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $this->access->manages($request->user(), $attempt->recurso->seccion->aula, 'aula.intentos.reactivar');
        abort_unless($attempt->estado === 'bloqueado', 422);
        abort_if($attempt->recurso->seccion->periodo->estaCerrado()
            || $attempt->recurso->estado === 'cerrado'
            || ($attempt->recurso->disponible_hasta && now('UTC')->gte($attempt->recurso->disponible_hasta)),
            422, 'El cuestionario ya no admite intentos. Ajusta primero su disponibilidad.');
        $before = $attempt->toArray();
        $duration = (int) ($attempt->recurso->configuracion['duracion_minutos'] ?? 60);
        $expires = now('UTC')->addMinutes($duration);
        if ($attempt->recurso->disponible_hasta && $attempt->recurso->disponible_hasta->lt($expires)) {
            $expires = $attempt->recurso->disponible_hasta;
        }
        $attempt->update(['estado' => 'en_curso', 'incidentes' => 0,
            'vence_at' => $expires, 'version' => $attempt->version + 1]);
        AuditLogger::tenant($request->user(), 'UPDATE', 'aula_intento', (string) $attempt->id, $before,
            $attempt->toArray(), $data['motivo']);

        return response()->json(['data' => ['token' => $token, 'estado' => $attempt->estado]]);
    }

    public function intentos(Request $request, string $resourceToken): JsonResponse
    {
        $resource = $this->quiz($resourceToken);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.calificar');
        $official = $resource->actividad_id ? Calificacion::where('actividad_id', $resource->actividad_id)
            ->get()->keyBy('matricula_id') : collect();

        return response()->json(['data' => AulaIntento::with('matricula.estudiante')->where('recurso_id', $resource->id)
            ->orderByDesc('id')->get()->map(fn ($attempt) => [
                'token' => Token::for('aula-intento', $attempt->id), 'estudiante' => $attempt->matricula->estudiante->name,
                'estado' => $attempt->estado, 'numero' => $attempt->numero,
                'nota' => $official->get($attempt->matricula_id)?->valor ?? $attempt->nota,
                'escala_opcion_token' => ($official->get($attempt->matricula_id)?->escala_opcion_id ?? $attempt->escala_opcion_id)
                    ? Token::for('escala-opcion', $official->get($attempt->matricula_id)?->escala_opcion_id ?? $attempt->escala_opcion_id) : null,
                'incidentes' => $attempt->incidentes, 'finalizado_at' => $attempt->finalizado_at,
                'estado_planilla' => ! $resource->llevar_planilla
                    || ($resource->configuracion['transferencia'] ?? 'confirmar') !== 'automatica'
                    || $attempt->estado !== 'finalizado' || $attempt->nota === null ? 'no_aplica'
                    : ($official->has($attempt->matricula_id) ? 'nota_oficial_existente' : 'pendiente'),
                'version' => $attempt->version,
            ])]);
    }

    /** La revisión de respuestas abiertas, de audio y video conserva el detalle por pregunta. */
    public function revisarPreguntas(Request $request, string $token): JsonResponse
    {
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $resource = $attempt->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.calificar');
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_unless($attempt->estado === 'pendiente_revision', 422,
            'Solo los intentos pendientes de revisión admiten esta operación.');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:8', 'max:1000'],
            'evaluaciones' => ['required', 'array', 'min:1', 'max:100'],
            'evaluaciones.*.pregunta_token' => ['required', 'string', 'size:24'],
            'evaluaciones.*.puntos' => ['required', 'numeric', 'min:0', 'max:100'],
            'evaluaciones.*.retroalimentacion' => ['nullable', 'string', 'max:5000'],
            'evaluaciones.*.criterios' => ['nullable', 'array', 'max:15'],
            'evaluaciones.*.criterios.*' => ['numeric', 'min:0', 'max:100'],
        ]);
        $attempt = DB::transaction(function () use ($attempt, $resource, $data, $request): AulaIntento {
            $locked = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'] && $locked->estado === 'pendiente_revision', 409,
                'El intento cambió; recarga sus respuestas antes de revisar.');
            $questions = AulaPregunta::where('recurso_id', $resource->id)->orderBy('orden')->get();
            $manual = $questions->filter(fn ($q) => in_array($q->tipo, ['abierta', 'audio', 'video'], true));
            abort_unless($manual->count() === count($data['evaluaciones']), 422,
                'Debes revisar todas las respuestas abiertas o multimedia del intento.');
            $reviews = [];
            foreach ($data['evaluaciones'] as $entry) {
                $question = Token::find('aula-pregunta', $entry['pregunta_token'],
                    AulaPregunta::where('recurso_id', $resource->id));
                abort_unless($question && in_array($question->tipo, ['abierta', 'audio', 'video'], true)
                    && ! isset($reviews[$entry['pregunta_token']]), 422, 'La pregunta de revisión no es válida.');
                $value = BigDecimal::of((string) $entry['puntos']);
                abort_if($value->isGreaterThan((string) $question->puntos), 422,
                    'El puntaje concedido supera el máximo de la pregunta.');
                $rubric = $question->rubrica ?? [];
                $criteria = $entry['criterios'] ?? [];
                if ($rubric) {
                    abort_unless(count($criteria) === count($rubric), 422, 'Completa todos los criterios de la rúbrica.');
                    $sum = BigDecimal::zero();
                    foreach ($rubric as $index => $criterion) {
                        $score = BigDecimal::of((string) $criteria[$index]);
                        abort_if($score->isGreaterThan((string) $criterion['puntos']), 422,
                            'Un criterio supera su puntaje máximo.');
                        $sum = $sum->plus($score);
                    }
                    abort_unless($sum->isEqualTo($value), 422,
                        'La suma de la rúbrica debe coincidir con los puntos otorgados.');
                } else {
                    abort_if($criteria !== [], 422, 'Esta pregunta no tiene rúbrica configurada.');
                }
                $reviews[$entry['pregunta_token']] = ['puntos' => (string) $value,
                    'retroalimentacion' => $entry['retroalimentacion'] ?? null,
                    'criterios' => $criteria];
            }
            $possible = BigDecimal::zero();
            $earned = BigDecimal::zero();
            foreach ($questions as $question) {
                $key = Token::for('aula-pregunta', $question->id);
                $possible = $possible->plus((string) $question->puntos);
                $earned = $earned->plus(isset($reviews[$key])
                    ? $reviews[$key]['puntos'] : $this->objectivePoints($question, ($locked->respuestas ?? [])[$key] ?? null));
            }
            $year = AnoLectivo::findOrFail($resource->seccion->aula->ano_lectivo_id);
            $config = app(SieeConfiguration::class)->resolve($year);
            $minimum = BigDecimal::of((string) $config['valor_min']);
            $range = BigDecimal::of((string) $config['valor_max'])->minus($minimum);
            $grade = (string) $minimum->plus($earned->dividedBy($possible, 8, RoundingMode::HALF_DOWN)
                ->multipliedBy($range))->toScale(8, RoundingMode::HALF_DOWN);
            $scale = app(EscalaVisualService::class)->forGroup($resource->seccion->aula->grupo);
            $choice = $scale ? app(EscalaVisualService::class)->nearestChoice($grade, $scale->opciones) : null;
            $before = $locked->toArray();
            $locked->update(['revision_preguntas' => $reviews, 'estado' => 'finalizado',
                'nota' => $grade, 'escala_opcion_id' => $choice?->id, 'version' => $locked->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_intento', (string) $locked->id,
                $before, $locked->toArray(), $data['motivo']);

            return $locked;
        });
        $transferred = false;
        if ($resource->llevar_planilla && ($resource->configuracion['transferencia'] ?? 'confirmar') === 'automatica') {
            try {
                $transferred = app(AulaGradebookService::class)->transferAutomatic($resource,
                    $request->user(), $attempt->matricula_id, (string) $attempt->nota);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return response()->json(['data' => ['estado' => $attempt->estado, 'nota' => $attempt->nota,
            'revision_preguntas' => $attempt->revision_preguntas, 'version' => $attempt->version,
            'transferida_planilla' => $transferred]]);
    }

    public function calificar(Request $request, string $token): JsonResponse
    {
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $resource = $attempt->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.calificar');
        abort_unless($resource->calificable, 422);
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_unless(in_array($attempt->estado, ['finalizado', 'pendiente_revision', 'tiempo_agotado'], true), 422);
        $data = $request->validate(['version' => ['required', 'integer'],
            'nota' => ['nullable', 'numeric'], 'escala_opcion_token' => ['nullable', 'string'],
            'motivo' => ['required', 'string', 'min:8', 'max:1000']]);
        abort_unless($attempt->version === $data['version'], 409, 'El intento cambió. Recarga antes de calificar.');
        $aula = $resource->seccion->aula;
        $scale = app(EscalaVisualService::class)->forGroup($aula->grupo);
        $choice = $scale && ! empty($data['escala_opcion_token'])
            ? Token::find('escala-opcion', $data['escala_opcion_token'], EscalaOpcion::where('escala_id', $scale->id)) : null;
        abort_if($scale && ! $choice, 422, 'Selecciona una categoría visual.');
        abort_if(! $scale && ! isset($data['nota']), 422, 'Indica la nota.');
        $value = $choice ? (string) $choice->valor_equivalente : (string) $data['nota'];
        $config = app(SieeConfiguration::class)->resolve(AnoLectivo::findOrFail($aula->ano_lectivo_id));
        abort_if(BigDecimal::of($value)->isLessThan((string) $config['valor_min'])
            || BigDecimal::of($value)->isGreaterThan((string) $config['valor_max']), 422, 'La nota está fuera de la escala.');
        DB::transaction(function () use ($attempt, $resource, $request, $value, $choice, $data): void {
            if ($resource->llevar_planilla) app(AulaGradebookService::class)->transfer($resource, $request->user(),
                $attempt->matricula_id, $value, $data['escala_opcion_token'] ?? null, $data['motivo']);
            $before = $attempt->toArray();
            $attempt->update(['nota' => $value, 'escala_opcion_id' => $choice?->id, 'estado' => 'finalizado',
                'version' => $attempt->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_intento', (string) $attempt->id, $before,
                $attempt->toArray(), $data['motivo']);
        });

        return response()->json(['data' => ['guardado' => true]]);
    }

    private function quiz(string $token): AulaRecurso
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::where('tipo', 'cuestionario'));
        abort_unless($resource, 404);

        return $resource;
    }

    private function ownActiveAttempt(Request $request, AulaIntento $attempt): void
    {
        $this->access->readResource($request->user(), $attempt->recurso);
        abort_if($attempt->recurso->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_if($attempt->recurso->estado === 'cerrado', 422, 'El cuestionario se cerró.');
        abort_if($attempt->recurso->disponible_hasta && now('UTC')->gte($attempt->recurso->disponible_hasta),
            422, 'El plazo del cuestionario terminó.');
        $enrollment = $this->access->enrollment($request->user(), $attempt->recurso->seccion->aula);
        abort_unless($request->user()->can('aula.evaluaciones.responder') && $enrollment?->id === $attempt->matricula_id, 403);
        abort_unless($attempt->estado === 'en_curso', 423, 'El intento no permite continuar.');
        abort_if($attempt->vence_at && now('UTC')->gte($attempt->vence_at), 422, 'El tiempo del intento terminó.');
    }

    private function sameAnswer(mixed $given, ?array $correct, string $type): bool
    {
        if ($given === null || $correct === null) return false;
        $expected = $correct['valor'] ?? null;
        if (is_array($given) && is_array($expected) && $type === 'multiple') {
            sort($given); sort($expected);
        }

        return $given === $expected;
    }

    private function attemptData(AulaIntento $attempt, AulaRecurso $resource, bool $student = false): array
    {
        $choice = $attempt->escala_opcion_id ? EscalaOpcion::findOrFail($attempt->escala_opcion_id) : null;
        $perPage = max(1, (int) ($resource->configuracion['preguntas_por_pagina'] ?? 100));
        $questions = $this->orderedQuestions($attempt, $resource);
        $visibleQuestions = $student && $attempt->estado === 'en_curso'
            ? $questions->slice(($attempt->pagina_actual - 1) * $perPage, $perPage)->values() : $questions;
        return ['token' => Token::for('aula-intento', $attempt->id), 'version' => $attempt->version,
            'estado' => $attempt->estado,
            'vence_at' => $attempt->vence_at, 'numero' => $attempt->numero, 'incidentes' => $attempt->incidentes,
            'nota' => $choice ? null : $attempt->nota,
            'valoracion' => $choice ? EscalaOpcionController::present($choice) : null,
            'vigilado' => (bool) ($resource->configuracion['vigilado'] ?? false),
            'incidentes_permitidos' => (int) ($resource->configuracion['incidentes_permitidos'] ?? 0),
            'duracion_minima_minutos' => (int) ($resource->configuracion['duracion_minima_minutos'] ?? 0),
            'pagina_actual' => $attempt->pagina_actual,
            'preguntas_por_pagina' => $perPage, 'preguntas_total' => $questions->count(),
            'permitir_regresar' => (bool) ($resource->configuracion['permitir_regresar'] ?? true),
            'permitir_editar_respuestas' => (bool) ($resource->configuracion['permitir_editar_respuestas'] ?? true),
            'respuestas' => $attempt->respuestas ?? [],
            ...($student ? [] : ['revision_preguntas' => $attempt->revision_preguntas ?? []]),
            'preguntas' => $visibleQuestions->map(function ($q) use ($attempt, $student) {
                $original = $q->opciones ?? [];
                $options = $student ? ($attempt->presentacion['opciones'][$q->id] ?? $original) : $original;
                if ($student && $q->tipo === 'orden' && ! $attempt->presentacion) $options = array_reverse($original);
                $choices = $q->tipo === 'correspondencia' && $student
                    ? ($attempt->presentacion['opciones'][$q->id]
                        ?? array_reverse($q->respuesta_correcta['valor'] ?? [])) : null;
                $media = $q->medios->map(function ($medium) use ($student, $original, $options, $q) {
                    $data = AulaMedioController::presentQuestion($medium);
                    if ($student && $q->tipo !== 'correspondencia' && $medium->opcion_indice !== null && $original !== $options) {
                        $value = $original[$medium->opcion_indice] ?? null;
                        $data['opcion_indice'] = array_search($value, $options, true);
                    }

                    return $data;
                })->values();

                return [
                    'token' => Token::for('aula-pregunta', $q->id), 'tipo' => $q->tipo,
                    'enunciado' => $q->enunciado, 'opciones' => $student && $q->tipo === 'correspondencia' ? $original : $options,
                    'puntos' => $q->puntos, 'medios' => $media,
                    ...($choices !== null ? ['respuestas_disponibles' => $choices] : []),
                    ...($student ? [] : ['rubrica' => $q->rubrica, 'puntajes_opciones' => $q->puntajes_opciones]),
                ];
            })->values()];
    }

    private function newPresentation(AulaRecurso $resource, array $config): array
    {
        $questions = $resource->preguntas()->orderBy('orden')->orderBy('id')->get();
        $ids = $questions->pluck('id')->all();
        if ($config['mezclar_preguntas'] ?? false) shuffle($ids);
        $options = [];
        foreach ($questions as $question) {
            $choices = $question->tipo === 'correspondencia'
                ? ($question->respuesta_correcta['valor'] ?? []) : ($question->opciones ?? []);
            if (($config['mezclar_respuestas'] ?? false)
                || in_array($question->tipo, ['orden', 'correspondencia'], true)) {
                $original = $choices;
                shuffle($choices);
                if (count($choices) > 1 && $choices === $original) {
                    $first = array_shift($choices);
                    $choices[] = $first;
                }
            }
            $options[$question->id] = $choices;
        }

        return ['preguntas' => $ids, 'opciones' => $options];
    }

    private function orderedQuestions(AulaIntento $attempt, AulaRecurso $resource)
    {
        $questions = $resource->preguntas()->with('medios')->orderBy('orden')->orderBy('id')->get();
        $order = $attempt->presentacion['preguntas'] ?? null;
        if (! is_array($order)) return $questions;
        $byId = $questions->keyBy('id');

        return collect($order)->map(fn ($id) => $byId->get($id))->filter()->values();
    }

    private function objectivePoints(AulaPregunta $question, mixed $given): BigDecimal
    {
        $weights = $question->puntajes_opciones;
        if ($weights && in_array($question->tipo, ['unica', 'multiple', 'booleano'], true)) {
            $chosen = $question->tipo === 'multiple' && is_array($given) ? array_unique($given)
                : (is_string($given) ? [$given] : []);
            $earned = BigDecimal::zero();
            foreach ($chosen as $value) {
                if (! is_string($value) || ! in_array($value, $question->opciones ?? [], true)) continue;
                $earned = $earned->plus((string) ($weights[$value] ?? 0));
            }
            $maximum = BigDecimal::of((string) $question->puntos);
            return $earned->isGreaterThan($maximum) ? $maximum : $earned;
        }

        return $this->sameAnswer($given, $question->respuesta_correcta, $question->tipo)
            ? BigDecimal::of((string) $question->puntos) : BigDecimal::zero();
    }

    private function questionData($questions): array
    {
        return $questions->map(fn ($q) => [
            'token' => Token::for('aula-pregunta', $q->id), 'tipo' => $q->tipo,
            'enunciado' => $q->enunciado, 'opciones' => $q->opciones,
            'respuesta_correcta' => $q->respuesta_correcta, 'puntos' => $q->puntos,
            'puntajes_opciones' => $q->puntajes_opciones, 'rubrica' => $q->rubrica,
            'medios' => $q->medios->map(AulaMedioController::presentQuestion(...))->values(),
        ])->values()->all();
    }
}
