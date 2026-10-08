<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorreoConfiguracion;
use App\Services\SchoolMail;
use App\Services\SchoolMailApproval;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolMailController extends Controller
{
    private function authorizeSchool(Request $r): void
    {
        // This is a non-delegable business rule, not just a matrix permission.
        abort_unless($r->user()->role === 'rector' && $r->user()->hasRole('rector') && $r->user()->can('config.correo'), 403);
        abort_if(app()->environment('production') && ! $r->isSecure(), 403, __('Configura el correo únicamente mediante HTTPS.'));
    }

    public function show(Request $r, SchoolMail $mail)
    {
        $this->authorizeSchool($r);
        $s = CorreoConfiguracion::find('gmail');
        $approval = app(SchoolMailApproval::class);
        $request = $approval->ownRequest($r->user());

        return response()->json(['configurado' => $s !== null && $s->desconectado_en === null, 'email' => $s?->email, 'nombre' => $s?->nombre,
            'verificado_en' => $s?->verificado_en?->toIso8601String(), 'transporte_plataforma' => $mail->ready(),
            'requiere_autorizacion' => $s !== null,
            'puede_editar' => ! $s || $approval->grant($r->user(), $s, 'editar') !== null,
            'puede_desconectar' => $s && $s->desconectado_en === null && $approval->grant($r->user(), $s, 'desconectar') !== null,
            'solicitud' => $request ? $approval->present($request, $s) : null])->header('Cache-Control', 'no-store, private');
    }

    public function requestChange(Request $r, SchoolMail $mail, SchoolMailApproval $approval)
    {
        $this->authorizeSchool($r);
        $d = $r->validate(['accion' => 'required|in:editar,desconectar', 'motivo' => 'required|string|min:10|max:1000']);
        $lock = Cache::lock('school-mail-settings:'.tenant()->getKey(), 90);
        abort_unless($lock->get(), 409, __('Hay un cambio de correo en curso.'));
        try {
            $saved = CorreoConfiguracion::find('gmail');
            abort_unless($saved, 422, __('La primera conexión no necesita autorización.'));
            abort_if($d['accion'] === 'desconectar' && $saved->desconectado_en, 422, __('La conexión ya está desconectada.'));
            $request = $approval->request($r->user(), $saved, $d);
            AuditLogger::tenant($r->user(), 'CREATE', 'correo-solicitud', $request->url_token, null, ['accion' => $d['accion']]);
        } finally {
            $lock->release();
        }

        return $this->show($r, $mail);
    }

    public function save(Request $r, SchoolMail $mail)
    {
        $this->authorizeSchool($r);
        $d = $r->validate(['email' => 'required|email|max:255', 'nombre' => 'required|string|max:120', 'app_password' => 'nullable|string|max:64']);
        $email = strtolower(trim($d['email']));
        $password = preg_replace('/\s+/', '', $d['app_password'] ?? '');
        $lock = Cache::lock('school-mail-settings:'.tenant()->getKey(), 90);
        abort_unless($lock->get(), 409, __('Ya hay una prueba de correo en curso.'));
        try {
            // Read retained credentials only after taking the lock, never from a stale concurrent save.
            $saved = CorreoConfiguracion::find('gmail');
            $approval = app(SchoolMailApproval::class);
            $approval->requireGrant($r->user(), $saved, 'editar');
            if ($password === '') {
                abort_unless($saved && ! $saved->desconectado_en && $saved->email === $email, 422, __('Introduce la contraseña de aplicación de este correo.'));
                $password = $saved->app_password;
            }
            abort_unless(preg_match('/^[a-zA-Z]{16}$/D', $password), 422, __('La contraseña de aplicación de Google tiene 16 letras. No uses tu contraseña habitual ni un código de verificación.'));
            $candidate = new CorreoConfiguracion(['key' => 'gmail', 'email' => $email, 'nombre' => $d['nombre'], 'app_password' => $password]);
            try {
                $mail->test($candidate);
            } catch (\RuntimeException $e) {
                abort(422, $e->getMessage());
            }
            try {
                $grant = DB::transaction(function () use ($r, $saved, $approval, $email, $d, $password) {
                    $current = CorreoConfiguracion::lockForUpdate()->find('gmail');
                    abort_unless($saved?->revision === $current?->revision, 409, __('La conexión cambió durante la prueba. Recarga la página.'));
                    $grant = $approval->requireGrant($r->user(), $current, 'editar');
                    $values = ['email' => $email, 'nombre' => $d['nombre'],
                        'app_password' => $password, 'verificado_en' => now(), 'desconectado_en' => null,
                        'revision' => Str::random(32), 'ultima_autorizacion' => $grant?->url_token];
                    // A missing row cannot be locked in PostgreSQL: first setup MUST be an insert,
                    // never an upsert that could overwrite a competing first connection.
                    if ($current) {
                        $current->update($values);
                    } else {
                        CorreoConfiguracion::create(['key' => 'gmail', ...$values]);
                    }

                    return $grant;
                });
            } catch (UniqueConstraintViolationException) {
                abort(409, __('Ya se guardó una conexión. Recarga la página y solicita autorización para cambiarla.'));
            }
            $approval->consumed($grant);
            AuditLogger::tenant($r->user(), 'UPDATE', 'correo-institucional', null, null, ['configurado' => true]);
        } finally {
            $lock->release();
        }

        return $this->show($r, $mail);
    }

    public function disconnect(Request $r, SchoolMail $mail)
    {
        $this->authorizeSchool($r);
        $lock = Cache::lock('school-mail-settings:'.tenant()->getKey(), 90);
        abort_unless($lock->get(), 409, __('Espera a que termine la prueba de correo.'));
        try {
            $approval = app(SchoolMailApproval::class);
            $grant = DB::transaction(function () use ($r, $approval) {
                $saved = CorreoConfiguracion::lockForUpdate()->find('gmail');
                $grant = $approval->requireGrant($r->user(), $saved, 'desconectar');
                abort_if($saved->desconectado_en, 422, __('La conexión ya está desconectada.'));
                // Keep a tombstone so disconnect cannot bypass the approval as a new first setup.
                $saved->update(['app_password' => null, 'desconectado_en' => now(), 'revision' => Str::random(32),
                    'ultima_autorizacion' => $grant->url_token]);

                return $grant;
            });
            $approval->consumed($grant);
            AuditLogger::tenant($r->user(), 'UPDATE', 'correo-institucional', null, null, ['configurado' => false]);
        } finally {
            $lock->release();
        }

        return $this->show($r, $mail);
    }
}
