<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaPregunta;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\Calificacion;
use App\Models\Academico\EscalaOpcion;
use App\Services\AulaAccess;
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
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_if(AulaIntento::where('recurso_id', $resource->id)->exists(), 422,
            'El cuestionario ya tiene intentos. No cambies sus respuestas históricas.');
        $data = $request->validate([
            'preguntas' => ['required', 'array', 'min:1', 'max:100'],
            'preguntas.*.tipo' => ['required', Rule::in(['unica', 'multiple', 'booleano', 'correspondencia', 'orden', 'abierta'])],
            'preguntas.*.enunciado' => ['required', 'string', 'max:10000'],
            'preguntas.*.opciones' => ['nullable', 'array', 'max:30'],
            'preguntas.*.respuesta_correcta' => ['nullable', 'array'],
            'preguntas.*.puntos' => ['required', 'numeric', 'min:0.001', 'max:100'],
        ]);
        foreach ($data['preguntas'] as $question) {
            if ($question['tipo'] !== 'abierta') {
                abort_if(empty($question['respuesta_correcta']), 422, 'Cada pregunta objetiva necesita una respuesta correcta.');
            }
        }
        DB::transaction(function () use ($resource, $data, $request): void {
            AulaPregunta::where('recurso_id', $resource->id)->delete();
            foreach ($data['preguntas'] as $order => $question) {
                AulaPregunta::create(['recurso_id' => $resource->id, 'tipo' => $question['tipo'],
                    'enunciado' => $question['enunciado'], 'opciones' => $question['opciones'] ?? null,
                    'respuesta_correcta' => $question['respuesta_correcta'] ?? null,
                    'puntos' => $question['puntos'], 'orden' => $order]);
            }
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_preguntas', (string) $resource->id, null,
                ['cantidad' => count($data['preguntas'])]);
        });

        return response()->json(['data' => ['guardado' => true]]);
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
            $attempt = AulaIntento::create(['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id,
                'numero' => ($latest?->numero ?? 0) + 1, 'estado' => 'en_curso', 'iniciado_at' => now('UTC'),
                'vence_at' => $expires, 'respuestas' => [], 'pagina_actual' => 1, 'version' => 1]);
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
        if (! $own) $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.gestionar');
        abort_unless($own || $request->user()->can('aula.evaluaciones.gestionar'), 403);

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
            $allowed = AulaPregunta::where('recurso_id', $attempt->recurso_id)->orderBy('orden')->orderBy('id')->pluck('id')
                ->map(fn ($id) => Token::for('aula-pregunta', $id))->all();
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
            $questions = AulaPregunta::where('recurso_id', $attempt->recurso_id)->get();
            $hasOpen = $questions->contains('tipo', 'abierta');
            $points = BigDecimal::zero();
            $earned = BigDecimal::zero();
            foreach ($questions as $question) {
                $points = $points->plus((string) $question->puntos);
                if ($question->tipo === 'abierta') continue;
                $given = ($attempt->respuestas ?? [])[Token::for('aula-pregunta', $question->id)] ?? null;
                if ($this->sameAnswer($given, $question->respuesta_correcta, $question->tipo)) $earned = $earned->plus((string) $question->puntos);
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

        return response()->json(['data' => ['estado' => $attempt->estado,
            'nota' => $attempt->escala_opcion_id ? null : $attempt->nota,
            'valoracion' => $attempt->escala_opcion_id
                ? EscalaOpcionController::present(EscalaOpcion::findOrFail($attempt->escala_opcion_id)) : null,
            'transferida_planilla' => $transferred]]);
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
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.gestionar');
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
                'version' => $attempt->version,
            ])]);
    }

    public function calificar(Request $request, string $token): JsonResponse
    {
        $attempt = Token::find('aula-intento', $token, AulaIntento::query());
        abort_unless($attempt, 404);
        $resource = $attempt->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.gestionar');
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
        $questions = $resource->preguntas;
        $visibleQuestions = $student && $attempt->estado === 'en_curso'
            ? $questions->slice(($attempt->pagina_actual - 1) * $perPage, $perPage)->values() : $questions;
        return ['token' => Token::for('aula-intento', $attempt->id), 'estado' => $attempt->estado,
            'vence_at' => $attempt->vence_at, 'numero' => $attempt->numero, 'incidentes' => $attempt->incidentes,
            'nota' => $choice ? null : $attempt->nota,
            'valoracion' => $choice ? EscalaOpcionController::present($choice) : null,
            'vigilado' => (bool) ($resource->configuracion['vigilado'] ?? false),
            'incidentes_permitidos' => (int) ($resource->configuracion['incidentes_permitidos'] ?? 0),
            'pagina_actual' => $attempt->pagina_actual,
            'preguntas_por_pagina' => $perPage, 'preguntas_total' => $questions->count(),
            'permitir_regresar' => (bool) ($resource->configuracion['permitir_regresar'] ?? true),
            'permitir_editar_respuestas' => (bool) ($resource->configuracion['permitir_editar_respuestas'] ?? true),
            'respuestas' => $attempt->respuestas ?? [],
            'preguntas' => $visibleQuestions->map(fn ($q) => [
                'token' => Token::for('aula-pregunta', $q->id), 'tipo' => $q->tipo,
                'enunciado' => $q->enunciado, 'opciones' => $q->opciones, 'puntos' => $q->puntos,
            ])];
    }
}
