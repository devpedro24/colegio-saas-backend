<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Jobs\SendEnrollmentNotice;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\PasswordPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EnrollmentIntake
{
    public const EDITABLE = ['borrador', 'correcciones'];

    public const REVIEWABLE = ['enviada', 'revision', 'correcciones', 'espera'];

    public const DOCUMENT_TYPES = ['RC', 'TI', 'CC', 'CE', 'PPT', 'PASAPORTE', 'OTRO'];

    public function requirePlan(): void
    {
        abort_unless(in_array('academico', Plan::where('key', tenant()?->plan)->value('features') ?? [], true), 403, __('El plan no incluye gestión académica.'));
    }

    public function resolve(string $type, string $token, Builder $query): mixed
    {
        return OpaqueUrlToken::find($type, $token, $query) ?? abort(404);
    }

    public function open(Campana $campaign): void
    {
        $today = now(tenant()->timezone ?: 'America/Bogota')->toDateString();
        abort_unless($campaign->abierta && $today >= $campaign->desde->toDateString() && ($campaign->hasta === null || $today <= $campaign->hasta->toDateString())
            && in_array(AnoLectivo::findOrFail($campaign->ano_lectivo_id)->estado, ['planificado', 'en_curso'], true), 422, __('La convocatoria no está abierta para nuevas cargas.'));
    }

    public function baseUrl(): string
    {
        $parts = parse_url(config('frontend.url'));
        $domain = tenant()->domains()->value('domain');
        abort_unless($domain, 422, __('El colegio necesita un dominio de acceso.'));
        // Sede registrations may be relative (norte.colegio), not full hosts.
        $root = tenant();
        while ($root->parent_id) {
            $root = Tenant::findOrFail($root->parent_id);
        }
        $rootDomain = $root->domains()->value('domain') ?? $domain;
        if (! str_contains($rootDomain, '.') && ! Str::endsWith($domain, '.'.($parts['host'] ?? 'localhost'))) {
            $domain .= '.'.($parts['host'] ?? 'localhost');
        }

        return ($parts['scheme'] ?? 'https').'://'.$domain.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function campaign(Campana $c): array
    {
        return ['url_token' => OpaqueUrlToken::for('ingreso-campana', $c->id), 'nombre' => $c->nombre,
            'ano_token' => OpaqueUrlToken::for('ano-lectivo', $c->ano_lectivo_id),
            'abierta' => $c->abierta, 'desde' => $c->desde->toDateString(), 'hasta' => $c->hasta?->toDateString(),
            'configuracion' => $c->configuracion, 'enlace' => $this->baseUrl().'/ingreso/'.OpaqueUrlToken::for('ingreso-campana', $c->id)];
    }

    public function present(Solicitud $s, bool $detail = true): array
    {
        $result = ['url_token' => OpaqueUrlToken::for('ingreso-solicitud', $s->id),
            'radicado' => 'MAT-'.strtoupper(substr(OpaqueUrlToken::for('ingreso-solicitud', $s->id), 0, 10)),
            'estado' => $s->estado, 'email' => $s->email, 'datos' => $s->datos ?? [],
            'grado_token' => OpaqueUrlToken::for('grado', $s->grado_id),
            'grado_aprobado_token' => $s->grado_aprobado_id ? OpaqueUrlToken::for('grado', $s->grado_aprobado_id) : null,
            'observacion' => $s->observacion, 'enviada_en' => $s->enviada_en?->toIso8601String()];
        if (! $detail) {
            return $result;
        }
        $result['campana'] = $this->campaign($s->campana);
        $result['documentos'] = $s->documentos()->orderByDesc('version')->get()->map(fn ($d) => [
            'url_token' => OpaqueUrlToken::for('ingreso-documento', $d->id), 'requisito' => $d->requisito,
            'version' => $d->version, 'nombre' => $d->nombre, 'estado' => $d->estado, 'observacion' => $d->observacion,
        ])->all();
        $result['historial'] = DB::table('ingreso_historial')->where('solicitud_id', $s->id)->orderByDesc('id')->limit(100)
            ->get(['evento', 'observacion', 'created_at'])->all();
        $result['grupo'] = $s->matricula_id ? Matricula::with('grupo.grado')->find($s->matricula_id)?->grupo?->nombre : null;

        return $result;
    }

    public function event(Solicitud $s, string $event, ?string $reason = null, ?User $actor = null): void
    {
        DB::table('ingreso_historial')->insert(['solicitud_id' => $s->id, 'evento' => $event,
            'observacion' => $reason, 'actor_id' => $actor?->id, 'created_at' => now()]);
        AuditLogger::tenant($actor, 'UPDATE', 'ingreso-solicitud', (string) $s->id, null, ['estado' => $s->estado, 'evento' => $event], $reason);
    }

    public function notify(Solicitud $s, string $key, string $subject, string $text): void
    {
        $notice = Notificacion::firstOrCreate(['clave' => $s->id.':'.$key], ['email' => $s->email,
            'asunto' => $subject, 'contenido' => $text."\n\nSeguimiento: ".$this->baseUrl().'/ingreso']);
        if (! $notice->enviada_en) {
            $school = (string) tenant()->getTenantKey();
            DB::afterCommit(function () use ($school, $notice) {
                try {
                    SendEnrollmentNotice::dispatch($school, $notice->id);
                } catch (\Throwable $e) {
                    report($e);
                } // Durable outbox remains retryable, the domain write already committed.
            });
        }
    }

    public function pin(Solicitud $s): void
    {
        $pin = strtoupper(bin2hex(random_bytes(6)));
        $s->update(['pin_hash' => Hash::make($pin), 'pin_expira' => now()->addDays(15)]);
        $this->notify($s, 'pin:'.Str::uuid(), 'PIN de tu solicitud de matrícula',
            'Tu PIN es '.$pin.'. Caduca en 15 días. Selecciona tu convocatoria y usa el correo con el que te registraste. No compartas este PIN.');
    }

    public function requirements(Solicitud $s): array
    {
        $grade = OpaqueUrlToken::for('grado', $s->grado_aprobado_id ?? $s->grado_id);

        return array_values(array_filter($s->campana->configuracion['documentos'],
            fn ($d) => empty($d['grados']) || in_array($grade, $d['grados'], true)));
    }

    public function complete(Solicitud $s, bool $approved = false): void
    {
        $docs = $s->documentos()->orderByDesc('version')->get()->unique('requisito')->keyBy('requisito');
        foreach ($this->requirements($s) as $requirement) {
            $doc = $docs->get($requirement['key']);
            abort_if($requirement['obligatorio'] && ! $doc, 422, __('Falta el documento: ').$requirement['nombre']);
            abort_if($doc && ($doc->estado === 'rechazado' || ($approved && $doc->estado !== 'aprobado')),
                422, __('Revisa el documento: ').$requirement['nombre']);
        }
    }

    /** Lock order shared with academic enrollment: year, campaign, request, group. */
    public function decide(Solicitud $request, User $actor, array $data): Solicitud
    {
        return DB::transaction(function () use ($request, $actor, $data) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($request->campana->ano_lectivo_id);
            $campaign = Campana::lockForUpdate()->findOrFail($request->campana_id);
            $s = Solicitud::lockForUpdate()->findOrFail($request->id);
            if ($s->estado === 'aprobada' && $data['estado'] === 'aprobada') {
                return $s;
            }
            abort_unless(in_array($year->estado, ['planificado', 'en_curso'], true), 422, __('El año lectivo está cerrado.'));
            abort_unless(in_array($s->estado, self::REVIEWABLE, true), 422, __('La solicitud no admite esta decisión.'));
            if ($data['estado'] === 'aprobada') {
                abort_if($s->estado === 'correcciones' || ! $s->email_verificado || ! $s->consentimiento_en || ! $s->enviada_en,
                    422, __('El aspirante debe completar y enviar la solicitud antes de aprobarla.'));
                $grade = $this->resolve('grado', $data['grado_token'], Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
                if ($grade->id !== $s->grado_id) {
                    abort_unless($actor->can('ingreso.cambiar_grado') && filled($data['observacion'] ?? null), 403, __('Cambiar el grado requiere permiso y motivo.'));
                }
                $setting = collect($campaign->configuracion['grados'])->firstWhere('token', $data['grado_token']);
                abort_unless($setting, 422, __('El grado no participa en esta convocatoria.'));
                $s->grado_aprobado_id = $grade->id;
                $this->complete($s, true);
                $pending = Solicitud::whereHas('campana', fn ($q) => $q->where('ano_lectivo_id', $year->id))
                    ->where('grado_aprobado_id', $grade->id)->where('estado', 'aprobada')->count();
                $active = Matricula::where('ano_lectivo_id', $year->id)->where('estado', 'activa')
                    ->whereHas('grupo', fn ($q) => $q->where('grado_id', $grade->id))->count();
                abort_if($pending + $active >= $setting['cupo'], 422, __('No quedan cupos en el grado. Usa la lista de espera.'));
                abort_if(User::withTrashed()->whereRaw('LOWER(email) = ?', [$s->email])->exists(), 422, __('Ese correo ya tiene cuenta. La renovación o vinculación de cuentas existentes debe gestionarse por separado.'));
                abort_if(DB::table('ingreso_perfiles')->where('tipo_documento', $s->datos['tipo_documento'])
                    ->where('numero_documento', $s->datos['numero_documento'])->exists(), 422, __('El documento ya pertenece a un estudiante.'));
                $limit = Plan::where('key', tenant()->plan)->value('max_estudiantes');
                abort_if($limit !== null && User::role('estudiante')->where('status', 'active')->count() >= $limit, 422, __('Se alcanzó el límite de estudiantes del plan.'));
                $password = PasswordPolicy::temporary(16);
                $student = User::create(['name' => trim(implode(' ', array_filter(array_map(fn ($k) => $s->datos[$k] ?? '',
                    ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'])))),
                    'email' => $s->email, 'password' => $password, 'temporary_password' => $password,
                    'status' => 'active', 'role' => 'estudiante', 'must_change_password' => true]);
                $student->forceFill(['temporary_password_expires_at' => now()->addDays(3)])->save();
                $student->assignRole('estudiante');
                DB::table('ingreso_perfiles')->insert(['estudiante_id' => $student->id,
                    'tipo_documento' => $s->datos['tipo_documento'], 'numero_documento' => $s->datos['numero_documento'],
                    'datos' => json_encode($s->datos), 'created_at' => now(), 'updated_at' => now()]);
                $s->estudiante_id = $student->id;
                $s->grado_aprobado_id = $grade->id;
                $this->notify($s, 'credenciales', 'Solicitud aprobada: acceso del estudiante',
                    'Tu solicitud fue aprobada. Grupo pendiente de asignación. Correo: '.$s->email."\nContraseña temporal: ".$password.
                    "\nCámbiala al ingresar. Caduca en 72 horas; después puedes usar Olvidé mi contraseña.\nIngreso: ".$this->baseUrl().'/auth');
            } else {
                abort_unless(filled($data['observacion'] ?? null), 422, __('Escribe el motivo de la decisión.'));
            }
            $s->estado = $data['estado'];
            $s->observacion = $data['observacion'] ?? null;
            $s->save();
            $this->event($s, $s->estado, $s->observacion, $actor);
            if ($s->estado !== 'aprobada') {
                $this->notify($s, 'decision:'.Str::uuid(), 'Actualización de matrícula',
                    'Estado: '.$s->estado.".\n".$s->observacion);
            }

            return $s;
        });
    }

    /** Preview never reserves seats; confirmation rechecks every seat under year locks. */
    public function distribute(Campana $campaign, array $pairs, bool $confirm, User $actor): array
    {
        return DB::transaction(function () use ($campaign, $pairs, $confirm, $actor) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($campaign->ano_lectivo_id);
            abort_unless(in_array($year->estado, ['planificado', 'en_curso'], true), 422, __('El año está cerrado.'));
            $pending = Solicitud::where('campana_id', $campaign->id)->where('estado', 'aprobada')->orderBy('id')->lockForUpdate()->get();
            abort_if(! $confirm && $pending->count() > 300, 422, __('La propuesta admite hasta 300 solicitudes. Asigna por grupos o de forma individual antes de generar otra propuesta.'));
            $groups = Grupo::where('ano_lectivo_id', $year->id)->where('estado', 'activo')->orderBy('id')->lockForUpdate()->get();
            $used = Matricula::whereIn('grupo_id', $groups->pluck('id'))->where('estado', 'activa')
                ->selectRaw('grupo_id, count(*) as total')->groupBy('grupo_id')->pluck('total', 'grupo_id')->all();
            $result = [];
            // Only explicit selections are confirmed; retries of an already assigned pair are no-ops.
            $selection = $confirm ? collect($pairs) : $pending->shuffle()->map(fn ($s) => ['solicitud_token' => OpaqueUrlToken::for('ingreso-solicitud', $s->id)]);
            foreach ($selection as $pair) {
                $s = $this->resolve('ingreso-solicitud', $pair['solicitud_token'], Solicitud::where('campana_id', $campaign->id)->lockForUpdate());
                $candidates = $groups->where('grado_id', $s->grado_aprobado_id);
                $g = $confirm ? $candidates->first(fn ($g) => OpaqueUrlToken::for('grupo', $g->id) === $pair['grupo_token'])
                    : $candidates->filter(fn ($g) => $g->cupo_maximo === null || ($used[$g->id] ?? 0) < $g->cupo_maximo)
                        ->sortBy(fn ($g) => $used[$g->id] ?? 0)->first();
                if (! $confirm && ! $g) {
                    continue;
                }
                abort_unless($g, 422, __('El grupo no corresponde al grado aprobado.'));
                if ($s->estado === 'matriculada') {
                    abort_unless(Matricula::find($s->matricula_id)?->grupo_id === $g->id, 422, __('La solicitud ya tiene otro grupo.'));

                    continue;
                }
                abort_unless($s->estado === 'aprobada', 422, __('La solicitud no está aprobada.'));
                abort_if($g->cupo_maximo !== null && ($used[$g->id] ?? 0) >= $g->cupo_maximo, 422, __('El grupo alcanzó su cupo. Genera otra propuesta.'));
                $used[$g->id] = ($used[$g->id] ?? 0) + 1;
                if ($confirm) {
                    $student = User::findOrFail($s->estudiante_id);
                    abort_unless($student->status === 'active' && $student->hasRole('estudiante'), 422, __('La cuenta del estudiante no está activa.'));
                    abort_if(Matricula::where('estudiante_id', $s->estudiante_id)->where('ano_lectivo_id', $year->id)->exists(), 422, __('El estudiante ya tiene matrícula este año.'));
                    $m = Matricula::create(['estudiante_id' => $s->estudiante_id, 'grupo_id' => $g->id, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
                    $s->update(['estado' => 'matriculada', 'matricula_id' => $m->id]);
                    $this->event($s, 'matriculada', 'Grupo: '.$g->nombre, $actor);
                    $this->notify($s, 'grupo', 'Matrícula confirmada', 'Tu matrícula fue confirmada en el grupo '.$g->nombre.'.');
                }
                $result[] = ['solicitud_token' => OpaqueUrlToken::for('ingreso-solicitud', $s->id),
                    'nombre' => $s->datos['primer_nombre'].' '.$s->datos['primer_apellido'],
                    'grupo_token' => OpaqueUrlToken::for('grupo', $g->id), 'grupo' => $g->nombre];
            }

            return ['asignaciones' => $result, 'sin_cupo' => $confirm ? 0 : $pending->count() - count($result)];
        });
    }
}
