<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All state and recipients live on the current tenant connection. No mail channel. */
final class AulaNotifications
{
    private array $contexts = [];

    private array $recipientCache = [];

    public function __construct(private readonly AulaAccess $access) {}

    public function sectionChanged(AulaSeccion $section, User $actor): void
    {
        $section->recursos()->withTrashed()->each(fn ($resource) => $this->resourceChanged($resource, $actor));
    }

    public function resourceChanged(AulaRecurso $resource, ?User $actor = null, bool $edited = false): void
    {
        DB::transaction(function () use ($resource, $actor, $edited): void {
            // Serialize publication transitions across requests and scheduler workers.
            $resource = AulaRecurso::withTrashed()->whereKey($resource->id)->lockForUpdate()->first();
            if (! $resource) {
                return;
            }
            DB::table('aula_notification_publications')->insertOrIgnore([
                'recurso_id' => $resource->id, 'visible' => false, 'revision' => 0,
                'actor_id' => $actor?->id ?? $resource->autor_id,
            ]);
            $state = DB::table('aula_notification_publications')->where('recurso_id', $resource->id)->lockForUpdate()->first();
            $visible = $this->access->visible($resource);
            $hash = $this->contentHash($resource);
            $published = $visible && ! $state->visible;
            $updated = $visible && $state->visible && $edited && $hash !== $state->content_hash;
            $revision = $state->revision + (int) ($published || $updated);
            $actorId = $actor?->id ?? $state->actor_id;
            DB::table('aula_notification_publications')->where('recurso_id', $resource->id)->update([
                'visible' => $visible, 'revision' => $revision, 'content_hash' => $hash, 'actor_id' => $actorId,
            ]);
            if ($published || $updated) {
                $event = $published ? 'aula.resource.published' : 'aula.resource.updated';
                $inserted = false;
                foreach ($this->recipients($resource->seccion->aula) as $recipient) {
                    $inserted = $this->insert($resource, $recipient, $event, 'resource:'.$revision) || $inserted;
                }
                if ($inserted) {
                    $this->changed('aula-content');
                }
            }
        }, 3);
    }

    /** Grade/review notices contain no score, feedback, answers, reasons or student name. */
    public function gradeSaved(AulaEntrega|AulaIntento $item, User $actor): void
    {
        $resource = $item->recurso;
        if (! $resource || ! $this->access->visible($resource)) {
            return;
        }
        $enrollment = Matricula::whereKey($item->matricula_id)
            ->where('grupo_id', $resource->seccion->aula->grupo_id)
            ->where('ano_lectivo_id', $resource->seccion->aula->ano_lectivo_id)->where('estado', 'activa')->first();
        $student = $enrollment?->estudiante;
        if (! $student || $student->id === $actor->id || $student->role !== 'estudiante'
            || ! $this->eligible($student, $resource->seccion->aula)) {
            return;
        }
        if ($this->insert($resource, $student, 'aula.grade.saved', $item->getTable().':'.$item->id.':'.$item->version)) {
            $this->changed('aula-grade');
        }
    }

    public function reconcileDue(): void
    {
        if (! app(AcademicPlanAccess::class)->aula()) {
            return;
        }
        // Only pending publications need a sweep. This also supports missed scheduler ticks.
        AulaRecurso::where('visible_estudiantes', true)->whereIn('estado', ['publicado', 'programado', 'cerrado'])
            ->where(fn ($query) => $query->whereNull('disponible_desde')->orWhere('disponible_desde', '<=', now('UTC')))
            ->whereHas('seccion', fn ($query) => $query->where('visible_estudiantes', true))
            ->whereIn('id', DB::table('aula_notification_publications')->where('visible', false)->select('recurso_id'))
            ->chunkById(100, function ($resources): void {
                foreach ($resources as $resource) {
                    $this->resourceChanged($resource);
                }
            });
    }

    public function eligible(User $user, Aula $aula): bool
    {
        $context = $this->context($aula);
        if ($user->status !== User::STATUS_ACTIVE || ! $context['valid']) {
            return false;
        }
        if ($user->can('aula.ver_todas')) {
            return true;
        }

        return $user->role === 'estudiante'
            ? $user->can('aula.ver_propias') && in_array($user->id, $context['students'], true)
            : $user->can('aula.ver_asignadas') && $context['teacher'] === $user->id;
    }

    public function enrolledStudent(User $user, Aula $aula): bool
    {
        return $user->role === 'estudiante' && in_array($user->id, $this->context($aula)['students'], true);
    }

    private function context(Aula $aula): array
    {
        $key = tenant()->getTenantKey().':'.$aula->id;

        return $this->contexts[$key] ??= [
            'valid' => $aula->grupo !== null && $aula->materia !== null && AnoLectivo::whereKey($aula->ano_lectivo_id)->exists(),
            'students' => Matricula::where('grupo_id', $aula->grupo_id)->where('ano_lectivo_id', $aula->ano_lectivo_id)
                ->where('estado', 'activa')->pluck('estudiante_id')->all(),
            'teacher' => AsignacionDocente::where('grupo_id', $aula->grupo_id)->where('ano_lectivo_id', $aula->ano_lectivo_id)
                ->where('materia_id', $aula->materia_id)->value('docente_id'),
        ];
    }

    private function recipients(Aula $aula): iterable
    {
        $key = tenant()->getTenantKey().':'.$aula->id;
        if (isset($this->recipientCache[$key])) {
            return $this->recipientCache[$key];
        }
        $context = $this->context($aula);

        // Restrict candidates in SQL; custom admin roles and direct grants are both supported.
        return $this->recipientCache[$key] = User::where('status', User::STATUS_ACTIVE)->with('roles.permissions', 'permissions')
            ->where(fn ($query) => $query->whereIn('id', $context['students'])->orWhere('id', $context['teacher'])
                ->orWhereHas('permissions', fn ($q) => $q->where('name', 'aula.ver_todas'))
                ->orWhereHas('roles.permissions', fn ($q) => $q->where('name', 'aula.ver_todas')))
            ->get()
            ->filter(fn ($user) => $this->eligible($user, $aula));
    }

    private function insert(AulaRecurso $resource, User $recipient, string $event, string $key): bool
    {
        return DB::table('user_notification_updates')->insertOrIgnore([
            'url_token' => Str::random(24), 'user_id' => $recipient->id, 'recurso_id' => $resource->id,
            'event' => $event, 'deduplication_key' => $key, 'created_at' => now('UTC'),
        ]) > 0;
    }

    private function changed(string $resource): void
    {
        app(RealtimeChanges::class)->record((string) tenant()->getTenantKey(), $resource);
    }

    private function contentHash(AulaRecurso $resource): string
    {
        $fields = $resource->only(['seccion_id', 'tipo', 'titulo', 'contenido', 'configuracion', 'estado',
            'calificable', 'llevar_planilla', 'peso', 'disponible_desde', 'disponible_hasta', 'fecha_limite', 'orden']);
        $fields['attachments'] = DB::table('aula_adjuntos')->where('recurso_id', $resource->id)->orderBy('id')
            ->get(['archivo_token', 'nombre'])->toArray();
        $fields['questions'] = $resource->preguntas()->with('medios')->get()->map(fn ($question) => [
            $question->only(['tipo', 'enunciado', 'opciones', 'respuesta_correcta', 'puntajes_opciones', 'rubrica', 'puntos', 'orden']),
            $question->medios->map->only(['archivo_token', 'opcion_indice', 'nombre'])->all(),
        ])->all();

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
