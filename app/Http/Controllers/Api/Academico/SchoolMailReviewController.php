<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Models\Academico\AulaRecurso;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\User;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\AulaAccess;
use App\Services\EventAccess;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SchoolMailReviewController
{
    private function publication(Request $request): int
    {
        $user = $request->user();
        $token = $request->route('publicacion');
        if ($request->route('modulo') === 'ingreso') {
            $intake = app(EnrollmentIntake::class);
            $intake->requirePlan();
            abort_unless($user->can('ingreso.ver'), 403);
            return $intake->resolve('ingreso-solicitud', $token, Solicitud::query())->id;
        }
        if ($request->route('modulo') === 'aula') {
            $resource = OpaqueUrlToken::find('aula-recurso', $token, AulaRecurso::with('seccion.aula'));
            abort_unless($resource, 404);
            app(AulaAccess::class)->content($user, $resource->seccion->aula, 'publicar');

            return $resource->id;
        }

        $access = app(EventAccess::class);
        $event = OpaqueUrlToken::find('evento', $token, $access->visible($user)->with('grupos'));
        abort_unless($event, 404);
        $access->authorizeWrite($user, [...$event->toArray(), 'grupo_ids' => $event->grupos->modelKeys()], $event);

        return $event->id;
    }

    private function notices(string $module, int $publication): Builder
    {
        return match ($module) {
            'ingreso' => DB::table('ingreso_notificaciones')->where('solicitud_id', $publication)->whereIn('tipo', Notificacion::REVIEW_TYPES),
            'aula' => DB::table('user_notification_updates')->where('recurso_id', $publication),
            'eventos' => DB::table('evento_correos')->where('evento_id', $publication),
        };
    }

    private function history(string $module, int $publication): Builder
    {
        return DB::table('correo_revisiones')->where('modulo', $module)->where('publicacion_id', $publication);
    }

    public function index(Request $request): JsonResponse
    {
        $publication = $this->publication($request);
        $module = $request->route('modulo');
        $aula = $module === 'aula';
        $query = $this->notices($module, $publication);
        $history = $this->history($module, $publication);
        if ($module === 'ingreso') {
            $pending = $query->where('estado', 'en_revision')->orderBy('intento_en')->orderBy('id')
                ->paginate(15, ['id', 'email', 'tipo', 'intentos', 'intento_en', 'created_at']);
            $rows = $pending->getCollection()->map(fn ($row) => [
                'selector' => OpaqueUrlToken::for('ingreso-aviso', $row->id), 'destinatario' => $row->email,
                'tipo' => 'ingreso.'.$row->tipo, 'intento' => (int) $row->intentos,
                'fecha' => Carbon::parse($row->intento_en ?? $row->created_at)->toISOString(),
            ]);
        } else {
            $table = $aula ? 'user_notification_updates' : 'evento_correos';
            $user = $aula ? 'user_id' : 'estudiante_id';
            $pending = $query->join('users', 'users.id', '=', $table.'.'.$user)
                ->where($table.'.'.($aula ? 'correo_estado' : 'estado'), 'en_revision')->select($table.'.*', 'users.name as nombre')
                ->orderBy($table.'.created_at')->orderBy($table.($aula ? '.url_token' : '.id'))->paginate(15);
            $rows = $pending->getCollection()->map(fn ($row) => [
                'selector' => $aula ? $row->url_token : OpaqueUrlToken::for('usuario', $row->estudiante_id),
                'destinatario' => $row->nombre, 'tipo' => $aula ? $row->event : 'calendar.event.published',
                'intento' => (int) ($aula ? $row->correo_intentos : $row->intentos),
                'fecha' => Carbon::parse($row->created_at)->toISOString(),
            ]);
        }
        $used = (clone $history)->whereIn('selector', $rows->pluck('selector'))->where('decision', 'reenviar')->pluck('selector')->flip();
        $decisions = $history->orderByDesc('id')->paginate(15, ['destinatario', 'responsable', 'decision', 'motivo', 'fecha', 'intento', 'tipo_aviso'], 'historial');
        foreach ($decisions as $entry) {
            $entry->fecha = Carbon::parse($entry->fecha)->toISOString();
        }

        return response()->json(['pendientes' => $rows->map(fn ($row) => [...$row, 'puede_reenviar' => ! $used->has($row['selector'])]),
            'paginas' => $pending->lastPage(), 'historial' => $decisions->items(), 'paginas_historial' => $decisions->lastPage()]);
    }

    public function decide(Request $request): JsonResponse
    {
        $publication = $this->publication($request);
        $module = $request->route('modulo');
        $aula = $module === 'aula';
        $selector = $request->route('selector');
        abort_unless(OpaqueUrlToken::valid($selector), 404);
        $input = $request->validate(['decision' => 'required|in:confirmar,reenviar', 'motivo' => 'required|string|max:1000',
            'intento' => 'required|integer|min:'.($module === 'ingreso' ? '0' : '1')]);
        $actor = $request->user();
        DB::transaction(function () use ($publication, $module, $aula, $selector, $input, $actor) {
            $query = $this->notices($module, $publication);
            if ($module === 'ingreso') {
                $notice = OpaqueUrlToken::find('ingreso-aviso', $selector, Notificacion::where('solicitud_id', $publication));
                abort_unless($notice, 404);
                $query->where('id', $notice->id);
            } elseif ($aula) {
                $query->where('url_token', $selector);
            } else {
                $student = OpaqueUrlToken::find('usuario', $selector, User::query());
                abort_unless($student, 404);
                $query->where('estudiante_id', $student->id);
            }
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            $state = $aula ? 'correo_estado' : 'estado';
            $attempt = (int) ($aula ? $row->correo_intentos : $row->intentos);
            abort_unless($row->$state === 'en_revision' && $attempt === (int) $input['intento'], 409, 'El envío cambió. Actualiza la revisión.');
            $resend = $input['decision'] === 'reenviar';
            $history = $this->history($module, $publication)->where('selector', $selector);
            abort_if($resend && $history->where('decision', 'reenviar')->exists(), 409, 'Este envío ya utilizó su autorización de reenvío.');
            $next = $resend ? 'reenvio' : 'confirmado';
            $available = match ($module) {
                'aula' => 'correo_disponible_en', 'eventos' => 'disponible_en', 'ingreso' => 'proximo_despacho_en',
            };
            $changes = [$state => $next, $available => $resend ? now() : null];
            if ($module === 'ingreso') {
                $changes['updated_at'] = now();
                if (! $resend) {
                    $changes['contenido'] = null;
                }
            } elseif (! $aula) {
                $changes['updated_at'] = now();
                $changes['ultimo_error'] = null;
            }
            $query->update($changes);
            $recipient = $module === 'ingreso' ? $row->email : User::findOrFail($aula ? $row->user_id : $row->estudiante_id)->name;
            DB::table('correo_revisiones')->insert(['modulo' => $module, 'publicacion_id' => $publication, 'selector' => $selector,
                'decision' => $input['decision'], 'intento' => $attempt, 'motivo' => $input['motivo'],
                'responsable_id' => $actor->id, 'responsable' => $actor->name, 'destinatario' => $recipient,
                'tipo_aviso' => $module === 'ingreso' ? 'ingreso.'.$row->tipo : null, 'fecha' => now()]);
            AuditLogger::tenant($actor, $resend ? 'AUTHORIZE_RESEND' : 'CONFIRM_MAIL', $module.'.correo', $selector,
                ['estado' => 'en_revision'], ['estado' => $next, 'intento' => $attempt, 'publicacion' => $publication], $input['motivo']);
        });

        return response()->json(['decision' => $input['decision']]);
    }
}
