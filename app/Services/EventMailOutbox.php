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
        return collect(['pendiente', 'enviando', 'enviado', 'fallido', 'cancelado'])->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function retry(Evento $event): void
    {
        abort_unless($event->estado === 'publicado' && $event->enviar_correo, 422);
        EventoCorreo::where('evento_id', $event->id)->where('estado', 'fallido')
            ->update(['estado' => 'pendiente', 'intentos' => 0, 'disponible_en' => now(), 'ultimo_error' => null]);
    }

    public function due()
    {
        return EventoCorreo::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('estado', 'pendiente')->where('disponible_en', '<=', now()))
            ->orWhere(fn ($q) => $q->where('estado', 'enviando')->where('updated_at', '<=', now()->subMinutes(5))));
    }

    public function deliverBatch(): void
    {
        $key = 'event-mail:'.tenant()->getTenantKey();
        // A shared cache and a dedicated long retry_after prevent overlapping SMTP batches.
        Cache::lock($key.':lock', 240)->get(function () use ($key) {
            if (Cache::has($key.':cooldown')) return;
            Cache::put($key.':cooldown', true, max(60, (int) config('events.batch_interval', 60)));
            $deadline = microtime(true) + 120;
            foreach ($this->due()->orderBy('id')->limit(min(10, max(1, (int) config('events.batch_size', 10))))->get() as $notice) {
                if (microtime(true) >= $deadline) break;
                $event = Evento::find($notice->evento_id);
                if (! $event || $event->estado !== 'publicado' || ! $event->enviar_correo
                    || ! $this->students($event)->whereKey($notice->estudiante_id)->exists()) {
                    $notice->update(['estado' => 'cancelado', 'ultimo_error' => 'out_of_scope']);
                    continue;
                }
                $notice->update(['estado' => 'enviando', 'intentos' => $notice->intentos + 1]);
                try {
                    $student = $this->students($event)->find($notice->estudiante_id);
                    if (! $student || ! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
                        $notice->update(['estado' => 'fallido', 'ultimo_error' => 'invalid_recipient']);
                        continue;
                    }
                    $notice->email = $student->email;
                    $notice->save();
                    app(SchoolMail::class)->sendEvent($notice->email, $event->correo_contenido, $notice->idioma);
                    $notice->update(['estado' => 'enviado', 'enviado_en' => now(), 'ultimo_error' => null]);
                } catch (\Throwable) {
                    // No SMTP transcript, credentials or recipient data in logs/failed payloads.
                    $attempt = $notice->intentos;
                    $delay = config('events.backoff')[$attempt - 1] ?? 21600;
                    $notice->update(['estado' => $attempt >= config('events.max_attempts', 5) ? 'fallido' : 'pendiente',
                        'disponible_en' => now()->addSeconds($delay), 'ultimo_error' => 'transport_failed']);
                }
            }
        });
    }
}
