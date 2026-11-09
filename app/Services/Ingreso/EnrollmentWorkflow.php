<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Solicitud;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EnrollmentWorkflow
{
    public function __construct(private EnrollmentIntake $intake) {}

    public function identityLookup(string $type, string $number): string
    {
        return hash_hmac('sha256', tenant()->getTenantKey().'|'.$type.'|'.Str::upper(trim($number)), (string) config('app.key'));
    }

    public function requestPin(Campana $campaign, array $data): Solicitud
    {
        return DB::transaction(function () use ($campaign, $data) {
            $campaign = Campana::lockForUpdate()->findOrFail($campaign->id);
            $this->intake->open($campaign);
            abort_unless(collect($campaign->configuracion['grados'])->contains('token', $data['grado_token']), 404);
            $grade = $this->intake->resolve('grado', $data['grado_token'], Grado::where('ano_lectivo_id', $campaign->ano_lectivo_id)->where('estado', 'activo'));
            $email = Str::lower(trim($data['email']));
            $lookup = $this->identityLookup($data['tipo_documento'], $data['numero_documento']);
            // A public request never reveals or overwrites an existing person's data.
            abort_if(Solicitud::where('campana_id', $campaign->id)->where(fn ($q) => $q->where('email', $email)->orWhere('identidad_lookup', $lookup))->exists(),
                422, __('Ya existe una solicitud con estos datos. Consulta al colegio para recuperar el PIN.'));
            abort_if(Solicitud::where('email', $email)->whereNotNull('identidad_lookup')->where('identidad_lookup', '!=', $lookup)->exists(),
                422, __('Cada estudiante debe utilizar un correo individual diferente.'));
            $account = User::whereRaw('lower(email) = ?', [$email])->first();
            if ($account) {
                $profile = DB::table('ingreso_perfiles')->where('estudiante_id', $account->id)->first();
                abort_unless($profile && $this->identityLookup($profile->tipo_documento, $profile->numero_documento) === $lookup,
                    422, __('El correo ya está registrado. Utiliza el correo individual del estudiante o consulta al colegio.'));
            }
            $application = Solicitud::create(['campana_id' => $campaign->id, 'grado_id' => $grade->id, 'email' => $email,
                'estado' => 'pin_pendiente', 'pin_manual' => true, 'identidad_lookup' => $lookup,
                'pin_hash' => Hash::make(Str::random(40)), 'pin_expira' => now(), 'sesion_version' => Str::random(64),
                'requisitos_snapshot' => $campaign->configuracion, 'correcciones' => [],
                'datos' => ['primer_nombre' => $data['primer_nombre'], 'primer_apellido' => $data['primer_apellido'],
                    'tipo_documento' => $data['tipo_documento'], 'numero_documento' => Str::upper(trim($data['numero_documento'])), 'adicionales' => []]]);
            $this->intake->event($application, 'pin_solicitado');

            return $application;
        });
    }

    public function activate(Solicitud $candidate, User $actor, ?string $expires): array
    {
        return DB::transaction(function () use ($candidate, $actor, $expires) {
            AnoLectivo::lockForUpdate()->findOrFail($candidate->campana->ano_lectivo_id);
            $application = Solicitud::lockForUpdate()->findOrFail($candidate->id);
            abort_unless($application->pin_manual, 422, __('Esta solicitud utiliza su enlace privado anterior.'));
            abort_if(in_array($application->estado, ['anulada', 'rechazada'], true), 422, __('La solicitud ya está cerrada.'));
            $deadline = $expires ? CarbonImmutable::parse($expires, tenant()->zonaHorariaInstitucional()) : null;
            abort_if($deadline && ! $deadline->isFuture(), 422, __('La vigencia del PIN debe estar en el futuro.'));
            if ($application->estado === 'pin_pendiente') {
                $this->intake->open($application->campana);
                $application->estado = 'borrador';
            }
            // Eight digits permit identification + PIN access without exposing a private link.
            $pin = (string) random_int(10000000, 99999999);
            $application->fill(['pin_hash' => Hash::make($pin), 'pin_expira' => $deadline?->utc(),
                'pin_intentos' => 0, 'pin_activado_en' => now(), 'sesion_version' => Str::random(64)])->save();
            $this->intake->event($application, 'pin_activado', null, $actor);
            $this->intake->notify($application, 'pin-manual:'.Str::uuid(), __('Tu PIN de inscripción está activo'),
                'Tu PIN es '.$pin.'. '.__('Ingresa con la identificación del estudiante para continuar.')."\n".$this->intake->baseUrl().'/ingreso');

            return ['data' => $this->intake->present($application), 'pin' => $pin];
        });
    }

    public function authenticate(array $data): ?Solicitud
    {
        return DB::transaction(function () use ($data) {
            $lookup = $this->identityLookup($data['tipo_documento'], $data['numero_documento']);
            $candidates = Solicitud::where('identidad_lookup', $lookup)->where('pin_manual', true)
                ->when($data['campana_token'] ?? null, fn ($q, $token) => $q->where('campana_id', $this->intake->resolve('ingreso-campana', $token, Campana::query())->id))
                ->where('estado', '!=', 'pin_pendiente')->orderByDesc('id')->lockForUpdate()->get();
            foreach ($candidates as $application) {
                if ($application->pin_intentos >= 5 || ($application->pin_expira && ! $application->pin_expira->isFuture())) {
                    continue;
                }
                if (Hash::check($data['pin'], $application->pin_hash)) {
                    return $application;
                }
                $application->increment('pin_intentos');
            }

            return null;
        });
    }

    public function editable(Solicitud $application): void
    {
        abort_unless(in_array($application->estado, EnrollmentIntake::EDITABLE, true), 422, __('La solicitud está en revisión o ya fue decidida.'));
        if ($application->estado === 'borrador') {
            $this->intake->open($application->campana);

            return;
        }
        $pending = collect($application->correcciones ?? [])->where('resuelta', false);
        if ($pending->isEmpty()) {
            $this->intake->open($application->campana);

            return;
        }
        abort_if($pending->contains(fn ($correction) => CarbonImmutable::parse($correction['hasta'])->isPast()),
            422, __('El plazo para corregir terminó. Consulta al colegio.'));
        $year = AnoLectivo::findOrFail($application->campana->ano_lectivo_id);
        abort_unless(in_array($year->estado, ['planificado', 'en_curso'], true), 422, __('El año lectivo está cerrado.'));
    }

    public function correct(Solicitud $candidate, User $actor, array $data): Solicitud
    {
        return DB::transaction(function () use ($candidate, $actor, $data) {
            $application = Solicitud::lockForUpdate()->findOrFail($candidate->id);
            abort_unless(in_array($application->estado, ['enviada', 'revision', 'correcciones', 'espera'], true), 422, __('La solicitud no está en revisión.'));
            $config = $this->intake->configurationFor($application);
            $fields = ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'nacimiento', 'telefono', 'pais', 'departamento', 'municipio', 'direccion', 'madre', 'padre', 'responsable', 'parentesco', 'telefono_responsable'];
            $fields = [...$fields, ...array_map(fn ($field) => 'adicionales.'.$field['key'], $config['campos'])];
            $targets = [...array_map(fn ($field) => 'datos.'.$field, $fields), ...array_map(fn ($doc) => 'documentos.'.$doc['key'], $this->intake->requirements($application))];
            abort_unless(in_array($data['campo'], $targets, true), 422, __('El elemento solicitado no pertenece al formulario.'));
            $deadline = CarbonImmutable::parse($data['hasta'], tenant()->zonaHorariaInstitucional());
            abort_unless($deadline->isFuture(), 422, __('El plazo para corregir debe estar en el futuro.'));
            $corrections = $application->correcciones ?? [];
            abort_if(collect($corrections)->contains(fn ($entry) => ! $entry['resuelta'] && $entry['campo'] === $data['campo']), 422, __('Ese elemento ya tiene una corrección pendiente.'));
            $version = null;
            if (str_starts_with($data['campo'], 'documentos.')) {
                $document = $application->documentos()->where('requisito', substr($data['campo'], 11))->orderByDesc('version')->first();
                abort_unless($document && $document->estado !== 'retirado', 422, __('El documento todavía no fue adjuntado.'));
                $version = $document->version;
                $document->update(['estado' => 'rechazado', 'observacion' => $data['motivo'], 'revisor_id' => $actor->id]);
            }
            $corrections[] = ['key' => (string) Str::uuid(), 'campo' => $data['campo'], 'motivo' => $data['motivo'],
                'hasta' => $deadline->utc()->toIso8601String(), 'version' => $version, 'resuelta' => false];
            $application->update(['estado' => 'correcciones', 'validada_en' => null, 'correcciones' => $corrections]);
            $this->intake->event($application, 'correccion_solicitada', $data['campo'].' — '.$data['motivo'], $actor);
            $this->intake->notify($application, 'correccion:'.Str::uuid(), __('Tu inscripción requiere una corrección'),
                $data['motivo']."\n".__('Plazo para corregir').' '.$deadline->format('d/m/Y H:i'));

            return $application;
        });
    }

    public function restrictChanges(Solicitud $application, array $data, array $files, array $removed, bool $final = true): void
    {
        if (! $application->pin_manual) {
            return;
        }
        foreach (['tipo_documento', 'numero_documento'] as $identity) {
            abort_unless(($data[$identity] ?? null) === ($application->datos[$identity] ?? null), 422, __('La identificación debe conservarse. Consulta al colegio para corregirla.'));
        }
        if ($application->estado !== 'correcciones') {
            return;
        }
        $pending = collect($application->correcciones ?? [])->where('resuelta', false);
        $allowed = $pending->pluck('campo')->all();
        $before = Arr::dot($application->datos ?? []);
        $after = Arr::dot($data);
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $value = $after[$key] ?? '';
            abort_if(($before[$key] ?? '') != ($value ?? '') && ! in_array('datos.'.$key, $allowed, true), 422, __('Solo puedes actualizar los elementos solicitados por el colegio.'));
        }
        foreach ([...array_keys($files), ...$removed] as $key) {
            abort_unless(in_array('documentos.'.$key, $allowed, true), 422, __('Solo puedes reemplazar los documentos solicitados.'));
        }
        foreach ($final ? $pending : [] as $correction) {
            $key = substr($correction['campo'], 11);
            $latest = str_starts_with($correction['campo'], 'documentos.') ? $application->documentos()->where('requisito', $key)->max('version') : null;
            abort_if(str_starts_with($correction['campo'], 'documentos.') && ! isset($files[$key]) && $latest <= $correction['version'],
                422, __('Adjunta una nueva versión del documento solicitado.'));
        }
    }

    public function validate(Solicitud $candidate, User $actor): Solicitud
    {
        return DB::transaction(function () use ($candidate, $actor) {
            $application = Solicitud::lockForUpdate()->findOrFail($candidate->id);
            abort_unless(in_array($application->estado, ['enviada', 'revision', 'espera'], true) && $application->enviada_en, 422, __('La inscripción debe estar enviada y sin correcciones pendientes.'));
            abort_if(collect($application->correcciones ?? [])->contains(fn ($entry) => ! $entry['resuelta']), 422, __('Todavía hay correcciones pendientes.'));
            $this->intake->complete($application, true);
            $application->update(['estado' => 'validada', 'validada_en' => now()]);
            $this->intake->event($application, 'validada', null, $actor);
            $this->intake->notify($application, 'validacion:'.Str::uuid(), __('Tu documentación fue validada'), __('La decisión de admisión se comunicará por separado.'));

            return $application;
        });
    }

    public function cancel(Solicitud $candidate, User $actor, string $reason): Solicitud
    {
        return DB::transaction(function () use ($candidate, $actor, $reason) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($candidate->campana->ano_lectivo_id);
            $application = Solicitud::lockForUpdate()->findOrFail($candidate->id);
            abort_unless(in_array($year->estado, ['planificado', 'en_curso'], true), 422, __('El año lectivo está cerrado.'));
            abort_unless($application->pin_manual && ! in_array($application->estado, ['matriculada', 'anulada', 'rechazada'], true), 422, __('La solicitud ya está cerrada.'));
            $application->update(['estado' => 'anulada', 'observacion' => $reason]);
            $this->intake->event($application, 'anulada', $reason, $actor);
            $this->intake->notify($application, 'anulacion:'.Str::uuid(), __('Tu inscripción fue anulada'), $reason);

            return $application;
        });
    }
}
