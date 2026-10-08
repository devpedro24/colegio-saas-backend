<?php

namespace App\Services;

use App\Models\CorreoConfiguracion;
use App\Models\SchoolMailChangeRequest;
use App\Models\User;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** A platform decision authorizes one actor, action and immutable configuration revision. */
class SchoolMailApproval
{
    public function ownRequest(User $actor): ?SchoolMailChangeRequest
    {
        return SchoolMailChangeRequest::where('tenant_id', (string) tenant()->getKey())
            ->where('requester_id', (string) $actor->getKey())->latest('id')->first();
    }

    public function state(SchoolMailChangeRequest $r, ?CorreoConfiguracion $settings): string
    {
        if ($settings?->ultima_autorizacion === $r->url_token) {
            return 'utilizada';
        }
        if ($r->estado === 'utilizada' || $r->estado === 'rechazada') {
            return $r->estado;
        }
        if (! $settings || $r->revision !== $settings->revision) {
            return 'obsoleta';
        }
        if ($r->estado === 'aprobada' && (! $r->expires_at || $r->expires_at->isPast())) {
            return 'vencida';
        }

        return $r->estado;
    }

    public function present(SchoolMailChangeRequest $r, ?CorreoConfiguracion $settings): array
    {
        return ['url_token' => $r->url_token, 'accion' => $r->accion, 'estado' => $this->state($r, $settings),
            'motivo' => $r->motivo, 'observacion' => $r->observacion,
            'creada_en' => $r->created_at->toIso8601String(), 'vence_en' => $r->expires_at?->toIso8601String()];
    }

    public function grant(User $actor, ?CorreoConfiguracion $settings, string $action): ?SchoolMailChangeRequest
    {
        if (! $settings) {
            return null;
        }

        return SchoolMailChangeRequest::where('tenant_id', (string) tenant()->getKey())
            ->where('requester_id', (string) $actor->getKey())->where('revision', $settings->revision)
            ->where('accion', $action)->where('estado', 'aprobada')->where('expires_at', '>', now())->latest('id')->first();
    }

    public function requireGrant(User $actor, ?CorreoConfiguracion $settings, string $action): ?SchoolMailChangeRequest
    {
        if (! $settings && $action === 'editar') {
            return null;
        } // The first connection only.
        $grant = $this->grant($actor, $settings, $action);
        abort_unless($grant, 403, __('La conexión está bloqueada. Solicita autorización al superadministrador para este cambio.'));

        return $grant;
    }

    public function request(User $actor, CorreoConfiguracion $settings, array $data): SchoolMailChangeRequest
    {
        $pending = SchoolMailChangeRequest::where('tenant_id', (string) tenant()->getKey())
            ->where('revision', $settings->revision)->where(function ($q) {
                $q->where('estado', 'pendiente')->orWhere(fn ($q) => $q->where('estado', 'aprobada')->where('expires_at', '>', now()));
            })->first();
        if ($pending) {
            abort_unless($pending->requester_id === (string) $actor->getKey() && $pending->accion === $data['accion'], 409,
                __('Ya existe una solicitud pendiente o autorización vigente para esta conexión.'));

            return $pending;
        }

        return SchoolMailChangeRequest::create(['url_token' => Str::random(24), 'tenant_id' => (string) tenant()->getKey(),
            'requester_id' => (string) $actor->getKey(), 'requester_name' => $actor->name,
            'revision' => $settings->revision, 'accion' => $data['accion'], 'motivo' => $data['motivo'], 'estado' => 'pendiente']);
    }

    public function consumed(?SchoolMailChangeRequest $grant): void
    {
        if (! $grant) {
            return;
        }
        // The tenant save already rotated the revision atomically, so this central bookkeeping
        // cannot re-enable a grant even if the central database fails after the tenant commit.
        try {
            $grant->update(['estado' => 'utilizada']);
        } catch (\Throwable) {
            Log::warning('School mail approval delivery bookkeeping pending.');
        }
        app(RealtimeChanges::class)->record(null, 'school-mail-requests', $grant->getConnection());
    }
}
