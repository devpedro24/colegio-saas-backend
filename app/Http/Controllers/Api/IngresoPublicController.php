<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Services\Ingreso\EnrollmentIntake;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\ReadableStorageName;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class IngresoPublicController extends Controller
{
    public function __construct(private EnrollmentIntake $intake) {}

    private function available(): void
    {
        $this->intake->requirePlan();
        abort_unless(tenant()->status === Tenant::STATUS_ACTIVE, 404);
    }

    private function campaign(string $token): Campana
    {
        $this->available();

        return $this->intake->resolve('ingreso-campana', $token, Campana::query());
    }

    private function session(Request $r, bool $lock = false): Solicitud
    {
        $this->available();
        try {
            $payload = Crypt::decryptString((string) $r->cookie('ingreso_session'));
            $p = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(401, __('Ingresa con tu correo y PIN.'));
        }
        abort_unless(($p['school'] ?? '') === (string) tenant()->getTenantKey() && ($p['expires'] ?? 0) > time(), 401);
        $q = Solicitud::query();
        if ($lock) {
            $q->lockForUpdate();
        }
        $s = $this->intake->resolve('ingreso-solicitud', $p['application'] ?? '', $q);
        abort_unless(hash_equals($s->sesion_version, $p['version'] ?? ''), 401);
        if (! $r->isMethodSafe()) {
            abort_unless($r->header('Origin') === $r->getSchemeAndHttpHost()
                && hash_equals($p['csrf'], (string) $r->header('X-CSRF-Token')), 419);
        }

        return $s;
    }

    private function editable(Solicitud $s): void
    {
        $this->intake->open($s->campana);
        abort_unless(in_array($s->estado, EnrollmentIntake::EDITABLE, true), 422, __('La solicitud está en revisión o ya fue decidida.'));
    }

    public function catalog()
    {
        $this->available();

        return response()->json(['colegio' => tenant()->name, 'campanas' => Campana::orderByDesc('id')->limit(50)->get()->map($this->intake->campaign(...))]);
    }

    public function start(Request $r, string $campaign)
    {
        $c = $this->campaign($campaign);
        $this->intake->open($c);
        $d = $r->validate(['email' => 'required|email|max:255', 'grado_token' => 'required|string|size:24']);
        abort_unless(collect($c->configuracion['grados'])->contains('token', $d['grado_token']), 404);
        $grade = $this->intake->resolve('grado', $d['grado_token'], Grado::where('ano_lectivo_id', $c->ano_lectivo_id)->where('estado', 'activo'));
        DB::transaction(function () use ($c, $d, $grade) {
            $lockedCampaign = Campana::lockForUpdate()->findOrFail($c->id);
            $this->intake->open($lockedCampaign);
            abort_unless(collect($lockedCampaign->configuracion['grados'])->contains('token', $d['grado_token']), 422);
            $s = Solicitud::firstOrCreate(['campana_id' => $c->id, 'email' => Str::lower(trim($d['email']))],
                ['grado_id' => $grade->id, 'pin_hash' => Hash::make(Str::random(32)), 'pin_expira' => now(), 'sesion_version' => Str::random(64)]);
            // Existing data is never overwritten by someone who only knows an email.
            $this->intake->pin($s);
        });

        return response()->json(['message' => __('Revisa tu correo. Te enviamos el PIN para verificarlo y continuar la solicitud.')], 202);
    }

    public function recover(Request $r, string $campaign)
    {
        $c = $this->campaign($campaign);
        $d = $r->validate(['email' => 'required|email|max:255']);
        DB::transaction(function () use ($c, $d) {
            $s = Solicitud::where('campana_id', $c->id)->where('email', Str::lower(trim($d['email'])))->lockForUpdate()->first();
            if ($s) {
                $this->intake->pin($s);
            }
        });

        return response()->json(['message' => __('Si existe una solicitud, recibirás un nuevo PIN en tu correo.')], 202);
    }

    public function login(Request $r, string $campaign)
    {
        $c = $this->campaign($campaign);
        $d = $r->validate(['email' => 'required|email|max:255', 'pin' => 'required|string|max:32']);
        $s = Solicitud::where('campana_id', $c->id)->where('email', Str::lower(trim($d['email'])))->first();
        abort_unless($s && $s->pin_expira->isFuture() && Hash::check(strtoupper(trim($d['pin'])), $s->pin_hash), 422, __('Correo o PIN inválido o vencido.'));
        if (! $s->email_verificado) {
            $s->update(['email_verificado' => now()]);
        }
        $csrf = Str::random(40);
        $minutes = 120;
        $cookie = Crypt::encryptString(json_encode(['school' => (string) tenant()->getTenantKey(),
            'application' => OpaqueUrlToken::for('ingreso-solicitud', $s->id), 'version' => $s->sesion_version,
            'csrf' => $csrf, 'expires' => now()->addMinutes($minutes)->timestamp]));

        return response()->json(['data' => $this->intake->present($s)])->cookie('ingreso_session', $cookie, $minutes, '/api/ingreso-publico', null, $r->isSecure(), true, false, 'strict')
            ->cookie('ingreso_csrf', $csrf, $minutes, '/', null, $r->isSecure(), false, false, 'strict');
    }

    public function show(Request $r)
    {
        return response()->json(['data' => $this->intake->present($this->session($r))]);
    }

    public function logout(Request $r)
    {
        $s = $this->session($r);
        $s->update(['sesion_version' => Str::random(64)]);

        return response()->json(['ok' => true])->withoutCookie('ingreso_session', '/api/ingreso-publico')->withoutCookie('ingreso_csrf');
    }

    public function save(Request $r)
    {
        $s = DB::transaction(function () use ($r) {
            $s = $this->session($r, true);
            $this->editable($s);
            $submit = $r->boolean('enviar');
            $required = $submit ? 'required' : 'nullable';
            $rules = ['enviar' => 'required|boolean', 'consentimiento' => $submit ? 'accepted' : 'nullable|boolean',
                'datos' => 'required|array:primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,nacimiento,tipo_documento,numero_documento,telefono,direccion,adicionales',
                'datos.primer_nombre' => "$required|string|max:80", 'datos.segundo_nombre' => 'nullable|string|max:80',
                'datos.primer_apellido' => "$required|string|max:80", 'datos.segundo_apellido' => 'nullable|string|max:80',
                'datos.nacimiento' => "$required|date_format:Y-m-d|before:today|after:1900-01-01",
                'datos.tipo_documento' => [$required, Rule::in(EnrollmentIntake::DOCUMENT_TYPES)],
                'datos.numero_documento' => "$required|string|regex:/^[a-zA-Z0-9-]{3,40}$/",
                'datos.telefono' => 'nullable|string|max:30', 'datos.direccion' => 'nullable|string|max:200',
                'datos.adicionales' => 'present|array'];
            foreach ($s->campana->configuracion['campos'] as $field) {
                $rules['datos.adicionales.'.$field['key']] = ($submit && $field['obligatorio'] ? 'required' : 'nullable')
                    .($field['tipo'] === 'fecha' ? '|date_format:Y-m-d' : '|string|max:1000');
            }
            $d = $r->validate($rules);
            abort_if(array_diff(array_keys($d['datos']['adicionales']), array_column($s->campana->configuracion['campos'], 'key')), 422, __('Hay campos no configurados.'));
            $s->datos = $d['datos'];
            if (isset($s->datos['numero_documento'])) {
                $s->datos = [...$s->datos, 'numero_documento' => strtoupper(trim($s->datos['numero_documento']))];
            }
            if ($submit) {
                $this->intake->complete($s);
                $s->estado = 'enviada';
                $s->enviada_en = now();
                $s->consentimiento_en = now();
            }
            $s->save();
            if ($submit) {
                $this->intake->event($s, 'enviada');
                $this->intake->notify($s, 'envio:'.Str::uuid(), 'Solicitud de matrícula recibida', 'Recibimos tu solicitud. Podrás consultar el estado de cada documento en el portal.');
            }

            return $s;
        });

        return response()->json(['data' => $this->intake->present($s)]);
    }

    public function upload(Request $r, string $requirement, StorageService $storage)
    {
        $s = DB::transaction(function () use ($r, $requirement, $storage) {
            $s = $this->session($r, true);
            $this->editable($s);
            $rule = collect($this->intake->requirements($s))->firstWhere('key', $requirement);
            abort_unless($rule, 404);
            $last = $s->documentos()->where('requisito', $requirement)->orderByDesc('version')->first();
            abort_if($last && $last->estado === 'aprobado', 422, __('Un documento aprobado no se puede reemplazar.'));
            $r->validate(['archivo' => ['required', 'file', 'mimes:'.implode(',', $rule['formatos']), 'max:'.($rule['max_mb'] * 1024)]]);
            $version = ($last?->version ?? 0) + 1;
            abort_if($version > 20, 422, __('Se alcanzó el límite de versiones. Contacta al colegio.'));
            $folder = 'matriculas/Ano_lectivo_'.ReadableStorageName::segment(AnoLectivo::findOrFail($s->campana->ano_lectivo_id)->nombre, 'Ano', 20)
                .'/'.ReadableStorageName::segment($s->campana->nombre, 'Campana', 40)
                .'/Solicitud_'.OpaqueUrlToken::for('ingreso-solicitud', $s->id).'/'.$requirement.'/Version_'.$version;
            try {
                $file = $storage->store($r->file('archivo'), $folder, $s->email);
            } catch (StorageException $e) {
                abort(422, $e->getMessage());
            }
            $s->documentos()->create(['requisito' => $requirement, 'version' => $version,
                'archivo_id' => $file->id, 'nombre' => $file->original_name, 'estado' => 'pendiente']);
            $this->intake->event($s, 'documento_cargado', $rule['nombre'].' (versión '.$version.')');

            return $s;
        });

        return response()->json(['data' => $this->intake->present($s)], 201);
    }

    public function download(Request $r, string $document)
    {
        $s = $this->session($r);
        $d = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $s->id));
        $file = StoredFile::where('tenant_id', tenant()->getTenantKey())->findOrFail($d->archivo_id);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
