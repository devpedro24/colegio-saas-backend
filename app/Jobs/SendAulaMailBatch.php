<?php

namespace App\Jobs;

use App\Models\Academico\AulaRecurso;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AcademicPlanAccess;
use App\Services\AulaAccess;
use App\Services\AulaNotifications;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\SchoolMail;
use App\Services\SchoolMailDeferred;
use App\Support\OpaqueUrlToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SendAulaMailBatch implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 240;
    public int $timeout = 180;
    public int $tries = 3;

    public function __construct(public string $school)
    {
        $this->onConnection(config('events.connection'));
        $this->onQueue(config('events.queue'));
    }

    public function uniqueId(): string
    {
        return $this->school;
    }

    public static function due(): Builder
    {
        return DB::table('user_notification_updates')->whereIn('correo_estado', ['pendiente', 'reenvio'])
            ->where('correo_disponible_en', '<=', now());
    }

    public function handle(): void
    {
        $school = Tenant::where('status', Tenant::STATUS_ACTIVE)->find($this->school);
        $school?->run(fn () => Cache::lock('aula-mail:batch', 240)->get(fn () => $this->deliver()));
    }

    public static function hasDue(): bool
    {
        return self::interrupted()->exists() || (! Cache::has('aula-mail:cooldown') && self::due()->exists());
    }

    private static function interrupted(): Builder
    {
        return DB::table('user_notification_updates')->whereIn('correo_estado', ['enviando', 'reenviando'])
            ->where('correo_disponible_en', '<=', now());
    }

    private function deliver(): void
    {
        self::interrupted()->update(['correo_estado' => 'en_revision']);
        $interval = max(60, (int) config('events.batch_interval', 60));
        if (! Cache::add('aula-mail:cooldown', true, $interval)) {
            return;
        }
        $limit = min(10, max(1, (int) config('events.batch_size')));
        $deadline = microtime(true) + 120;
        foreach (self::due()->orderBy('correo_disponible_en')->orderBy('created_at')->orderBy('url_token')->limit($limit)->get() as $notice) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $row = DB::table('user_notification_updates')->where('url_token', $notice->url_token);
            $resend = $notice->correo_estado === 'reenvio';
            $inFlight = $resend ? 'reenviando' : 'enviando';
            $pending = (clone $row)->where('correo_estado', $notice->correo_estado)->where('correo_disponible_en', '<=', now())
                ->where('correo_intentos', $notice->correo_intentos);
            $resource = AulaRecurso::with('seccion.aula.materia')->find($notice->recurso_id);
            $student = User::find($notice->user_id);
            $audience = app(AulaNotifications::class);
            if (! $resource || ! $student || ! ($resource->configuracion['enviar_correo'] ?? false)
                || ! app(AcademicPlanAccess::class)->aula() || ! app(AulaAccess::class)->visible($resource)
                || ! $audience->enrolledStudent($student, $resource->seccion->aula)
                || ! $audience->eligible($student, $resource->seccion->aula)) {
                $pending->update(['correo_estado' => 'cancelado']);
                continue;
            }
            if (! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
                $pending->update(['correo_estado' => $resend ? 'en_revision' : 'fallido']);
                continue;
            }
            $english = tenant()->locale === 'en';
            $subject = match ($notice->event) {
                'aula.grade.saved' => $english ? 'Your classroom review is available' : 'Tu revisión de Aula está disponible',
                'aula.resource.updated' => $english ? 'Updated classroom resource' : 'Recurso actualizado en Aula',
                default => $english ? 'New classroom resource' : 'Nuevo recurso en Aula',
            };
            $url = app(EnrollmentIntake::class)->baseUrl().'/evaluacion/aula/recursos/'.OpaqueUrlToken::for('aula-recurso', $resource->id);
            $body = $resource->titulo."\n".$resource->seccion->aula->materia->nombre."\n\n".$url;
            $attempt = $notice->correo_intentos + 1;
            if (! $pending->update(['correo_estado' => $inFlight, 'correo_intentos' => $attempt,
                'correo_disponible_en' => now()->addMinutes(SchoolMail::REVIEW_AFTER_MINUTES)])) {
                continue;
            }
            $sending = $row->where('correo_estado', $inFlight)->where('correo_intentos', $attempt);
            try {
                app(SchoolMail::class)->sendAula($student->email, $subject, $body);
            } catch (SchoolMailDeferred $deferred) {
                $sending->update(['correo_estado' => $notice->correo_estado, 'correo_intentos' => $notice->correo_intentos,
                    'correo_disponible_en' => now()->addSeconds($deferred->seconds)]);
                Cache::put('aula-mail:cooldown', true, max($interval, $deferred->seconds));
                break;
            } catch (\Throwable) {
                $delay = config('events.backoff')[$attempt - 1] ?? 21600;
                $sending->update(['correo_disponible_en' => now()->addSeconds($delay),
                    'correo_estado' => $resend ? 'en_revision' : ($attempt >= config('events.max_attempts') ? 'fallido' : 'pendiente')]);
                continue;
            }
            $sending->update(['correo_estado' => 'enviado', 'correo_disponible_en' => null]);
        }
    }
}
