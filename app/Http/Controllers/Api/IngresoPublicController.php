<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentRenewal;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            abort(401, __('Consulta tu matrícula con el PIN.'));
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

        return response()->json(['colegio' => tenant()->name, 'limites_envio' => EnrollmentSubmission::limits(),
            'zona_horaria' => tenant()->zonaHorariaInstitucional()]);
    }

    public function campaignDetail(string $campaign)
    {
        return response()->json(['data' => $this->intake->campaign($this->campaign($campaign))]);
    }

    public function start(Request $r, string $campaign)
    {
        $c = $this->campaign($campaign);
        $this->intake->open($c);
        $d = $r->validate(['email' => 'required|email|max:255', 'grado_token' => 'required|string|size:24']);
        abort_unless(collect($c->configuracion['grados'])->contains('token', $d['grado_token']), 404);
        $grade = $this->intake->resolve('grado', $d['grado_token'], Grado::where('ano_lectivo_id', $c->ano_lectivo_id)->where('estado', 'activo'));
        try {
            [$access, $pinExpiry] = DB::transaction(function () use ($c, $d, $grade) {
                $lockedCampaign = Campana::lockForUpdate()->findOrFail($c->id);
                $this->intake->open($lockedCampaign);
                abort_unless(collect($lockedCampaign->configuracion['grados'])->contains('token', $d['grado_token']), 422);
                $s = Solicitud::firstOrCreate(['campana_id' => $c->id, 'email' => Str::lower(trim($d['email']))],
                    ['grado_id' => $grade->id, 'pin_hash' => Hash::make(Str::random(32)), 'pin_expira' => now(), 'sesion_version' => Str::random(64)]);
                // Existing data is never overwritten by someone who only knows an email.
                $access = $this->intake->pin($s);

                return [$access, $s->pin_expira];
            });
        } catch (\RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            abort(503, __('No se pudo enviar el PIN. Revisa la conexión de correo institucional e inténtalo de nuevo.'));
        }

        // Without a deadline this browser challenge is a session cookie; the
        // private email link remains usable after the browser session ends.
        $challengeMinutes = $pinExpiry ? max(1, (int) ceil(($pinExpiry->timestamp - time()) / 60)) : 0;

        return response()->json(['message' => __('Revisa tu correo. Te enviamos el PIN para verificarlo y continuar la solicitud.')], 202)
            ->cookie('ingreso_challenge', $access, $challengeMinutes, '/api/ingreso-publico', null, $r->isSecure(), true, false, 'strict');
    }

    public function consult(Request $r)
    {
        $this->available();
        $d = $r->validate(['pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/'],
            'access' => ['nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $pin = $d['pin'];
        $access = $d['access'] ?? $r->cookie('ingreso_challenge');
        abort_unless(is_string($access) && preg_match('/\A[a-f0-9]{64}\z/', $access), 422, __('Abre el enlace privado del correo e ingresa el PIN.'));
        $s = DB::transaction(function () use ($access, $pin) {
            $request = Solicitud::where('access_lookup', $this->intake->accessLookup($access))->lockForUpdate()->first();
            if (! $request || ($request->pin_expira && ! $request->pin_expira->isFuture()) || $request->pin_intentos >= 5) {
                return null;
            }
            if (! Hash::check($pin, $request->pin_hash)) {
                $request->increment('pin_intentos');

                return null;
            }

            return $request;
        });
        abort_unless($s, 422, __('PIN inválido o vencido. Solicita uno nuevo desde el enlace de matrícula.'));

        return $this->issueSession($r, $s);
    }

    private function issueSession(Request $r, Solicitud $s)
    {
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

        return response()->json(['ok' => true])->withoutCookie('ingreso_session', '/api/ingreso-publico')->withoutCookie('ingreso_csrf')
            ->withoutCookie('ingreso_challenge', '/api/ingreso-publico');
    }

    public function geography()
    {
        $this->available();

        return response()->json(['data' => EnrollmentSubmission::geography()]);
    }

    public function renewal(Request $r, EnrollmentRenewal $renewal)
    {
        $d = $r->validate(['tipo_documento' => 'required|string|in:RC,TI,CC,CE,PASAPORTE,OTRO',
            'numero_documento' => 'required|string|regex:/^[a-zA-Z0-9-]{3,40}$/']);
        return response()->json(['data' => DB::transaction(function () use ($r, $d, $renewal) {
            $s = $this->session($r, true);
            $this->editable($s);

            return $renewal->prefill($s, $d['tipo_documento'], $d['numero_documento']);
        })]);
    }

    public function save(Request $r, EnrollmentSubmission $submission)
    {
        $created = [];
        try {
            $s = DB::transaction(function () use ($r, $submission, &$created) {
                $candidate = $this->session($r);
                // Serialize submissions for the same academic year before locking
                // an application, so two campaigns cannot renew one account twice.
                AnoLectivo::lockForUpdate()->findOrFail($candidate->campana->ano_lectivo_id);
                $s = $this->session($r, true);
                $r->validate(['envio_token' => 'required|uuid']);
                // A retry after a successful response was lost cannot create duplicate files/notices.
                if (Notificacion::where('clave', $s->id.':envio:'.$r->input('envio_token'))->exists()) {
                    return $s;
                }
                $this->editable($s);
                $submission->submit($r, $s, $created);

                return $s;
            });
        } catch (\Throwable $error) {
            foreach ($created as $file) {
                Storage::disk($file->disk)->delete($file->path);
                $file->forceDelete();
            }
            throw $error;
        }

        return response()->json(['data' => $this->intake->present($s)]);
    }

    public function upload(Request $r, string $requirement)
    {
        $this->session($r);
        abort(410, __('intake.finalSubmissionOnly'));
    }

    public function removeDocument(Request $r, string $requirement)
    {
        $this->session($r);
        abort(410, __('intake.finalSubmissionOnly'));
    }

    public function download(Request $r, string $document)
    {
        $s = $this->session($r);
        $d = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $s->id));
        $file = StoredFile::where('tenant_id', tenant()->getTenantKey())->findOrFail($d->archivo_id);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
