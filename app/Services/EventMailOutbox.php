<?php

namespace App\Services;

use App\Models\Academico\Evento;
use App\Models\Academico\EventoCorreo;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\Cache;

final class EventMailOutbox
{
    public function students(Evento $event)
    {
        return User::where('status', 'active')->where(fn ($q) => $q->whereNull('role')->orWhere('role', 'estudiante'))
            ->where(fn ($q) => $q->where('role', 'estudiante')
            ->orWhereHas('roles', fn ($r) => $r->where('name', 'estudiante')))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', '!=', 'estudiante'))
            ->whereExists(function ($q) use ($event) {
                $q->selectRaw('1')->from('matriculas')->join('anos_lectivos', 'anos_lectivos.id', '=', 'matriculas.ano_lectivo_id')
                    ->join('grupos', 'grupos.id', '=', 'matriculas.grupo_id')
                    ->whereColumn('matriculas.estudiante_id', 'users.id')->where('matriculas.estado', 'activa')
                    ->whereNull('anos_lectivos.deleted_at')->whereNull('grupos.deleted_at')->where('grupos.estado', 'activo')
                    ->whereNotIn('anos_lectivos.estado', ['cerrado', 'archivado']);
                if (! $event->institucional) $q->whereIn('matriculas.grupo_id', $event->grupos->pluck('id'));
            });
    }

    /** Called under the event publication transaction/lock, once only. */
    public function prepare(Evento $event): void
    {
        if (! $event->enviar_correo) return;
        abort_unless(app(SchoolMail::class)->readyForEvents(), 422, __('Configura el correo institucional antes de publicar con notificación.'));
        $event->correo_contenido = [
            'school' => tenant()->name, 'title' => $event->titulo, 'description' => $event->descripcion,
            'date' => $event->fecha, 'time' => $event->hora_inicio,
            'zone' => tenant()->zonaHorariaInstitucional(),
            'url' => app(\App\Services\Ingreso\EnrollmentIntake::class)->baseUrl().'/comunicacion/eventos?evento='.OpaqueUrlToken::for('evento', $event->id),
        ];
        $event->save();
        foreach ($this->students($event)->orderBy('id')->lazyById(200) as $student) {
            $valid = filter_var($student->email, FILTER_VALIDATE_EMAIL) !== false;
            EventoCorreo::firstOrCreate(['evento_id' => $event->id, 'estudiante_id' => $student->id], [
                'email' => $student->email, 'idioma' => tenant()->locale === 'en' ? 'en' : 'es',
                'estado' => $valid ? 'pendiente' : 'fallido', 'disponible_en' => now(),
                'ultimo_error' => $valid ? null : 'invalid_recipient',
            ]);
        }
    }

    public function summary(Evento $event): array
    {
        $counts = EventoCorreo::where('evento_id', $event->id)->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->pluck('total', 'estado');
        $counts['pendiente'] = ($counts['pendiente'] ?? 0) + ($counts['reenvio'] ?? 0);
        $counts['enviando'] = ($counts['enviando'] ?? 0) + ($counts['reenviando'] ?? 0);
        return collect(['pendiente', 'enviando', 'en_revision', 'enviado', 'confirmado', 'fallido', 'cancelado'])->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function retry(Evento $event): void
    {
        abort_unless($event->estado === 'publicado' && $event->enviar_correo, 422);
        EventoCorreo::where('evento_id', $event->id)->where('estado', 'fallido')
            ->update(['estado' => 'pendiente', 'intentos' => 0, 'disponible_en' => now(), 'ultimo_error' => null]);
    }

    public function due()
    {
        return EventoCorreo::whereIn('estado', ['pendiente', 'reenvio'])->where('disponible_en', '<=', now());
    }

    private function interrupted()
    {
        return EventoCorreo::whereIn('estado', ['enviando', 'reenviando'])->where('updated_at', '<=', now()->subMinutes(SchoolMail::REVIEW_AFTER_MINUTES));
    }

    public function hasDue(): bool
    {
        return $this->interrupted()->exists() || (! Cache::has('event-mail:'.tenant()->getTenantKey().':cooldown') && $this->due()->exists());
    }

    public function deliverBatch(): void
    {
        $key = 'event-mail:'.tenant()->getTenantKey();
        // A shared cache and a dedicated long retry_after prevent overlapping SMTP batches.
        Cache::lock($key.':lock', 240)->get(function () use ($key) {
            $this->interrupted()->update(['estado' => 'en_revision', 'ultimo_error' => 'delivery_unconfirmed']);
            $interval = max(60, (int) config('events.batch_interval', 60));
            if (! Cache::add($key.':cooldown', true, $interval)) return;
            $deadline = microtime(true) + 120;
            foreach ($this->due()->orderBy('id')->limit(min(10, max(1, (int) config('events.batch_size', 10))))->get() as $notice) {
                if (microtime(true) >= $deadline) break;
                $event = Evento::find($notice->evento_id);
                $resend = $notice->estado === 'reenvio';
                $inFlight = $resend ? 'reenviando' : 'enviando';
                $pending = $this->due()->whereKey($notice->id)->where('estado', $notice->estado)->where('intentos', $notice->intentos);
                $student = $event && $event->estado === 'publicado' && $event->enviar_correo
                    ? $this->students($event)->find($notice->estudiante_id) : null;
                if (! $student) {
                    $pending->update(['estado' => 'cancelado', 'ultimo_error' => 'out_of_scope']);
                    continue;
                }
                if (! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
                    $pending->update(['estado' => $resend ? 'en_revision' : 'fallido', 'ultimo_error' => 'invalid_recipient']);
                    continue;
                }
                $attempt = $notice->intentos + 1;
                // Apply the encrypted cast before the conditional database update.
                $notice->email = $student->email;
                if (! $pending->update(['estado' => $inFlight, 'intentos' => $attempt, 'email' => $notice->getAttributes()['email']])) {
                    continue;
                }
                $sending = EventoCorreo::whereKey($notice->id)->where('estado', $inFlight)->where('intentos', $attempt);
                try {
                    app(SchoolMail::class)->sendEvent($student->email, $event->correo_contenido, $notice->idioma);
                } catch (SchoolMailDeferred $deferred) {
                    $sending->update(['estado' => $notice->estado, 'intentos' => $notice->intentos,
                        'disponible_en' => now()->addSeconds($deferred->seconds)]);
                    Cache::put($key.':cooldown', true, max($interval, $deferred->seconds));
                    break;
                } catch (\Throwable) {
                    // No SMTP transcript, credentials or recipient data in logs/failed payloads.
                    $delay = config('events.backoff')[$attempt - 1] ?? 21600;
                    $sending->update(['estado' => $resend ? 'en_revision' : ($attempt >= config('events.max_attempts', 5) ? 'fallido' : 'pendiente'),
                        'disponible_en' => now()->addSeconds($delay), 'ultimo_error' => 'transport_failed']);
                    continue;
                }
                $sending->update(['estado' => 'enviado', 'enviado_en' => now(), 'ultimo_error' => null]);
            }
        });
    }
}
