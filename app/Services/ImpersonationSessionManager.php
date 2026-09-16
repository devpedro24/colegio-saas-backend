<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Impersonation\ImpersonationAccess;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Ciclo de vida fail-closed de las sesiones y tokens de suplantacion. */
final class ImpersonationSessionManager
{
    /**
     * Crea una sesion y termina atomicamente todas las anteriores del mismo
     * superadmin, incluso si apuntaban a otro tenant.
     */
    public function start(
        User $superadmin,
        Tenant $tenant,
        string $sessionId,
        Carbon $expiresAt,
        string $motivo,
        ?string $ticket,
    ): Impersonation {
        [$session, $ended] = DB::connection($this->centralConnection())
            ->transaction(function () use (
                $superadmin, $tenant, $sessionId, $expiresAt, $motivo, $ticket,
            ): array {
                $this->lockSuperadmin($superadmin);

                $ended = Impersonation::query()
                    ->where('superadmin_id', $superadmin->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->get();

                $this->markCollectionEnded($ended);

                $session = Impersonation::create([
                    'session_id' => $sessionId,
                    'superadmin_id' => $superadmin->id,
                    'superadmin_email' => $superadmin->email,
                    'tenant_id' => (string) $tenant->id,
                    'motivo' => $motivo,
                    'ticket' => $ticket ?: null,
                    'started_at' => Carbon::now('UTC'),
                    'expires_at' => $expiresAt,
                    'ended_at' => null,
                ]);

                return [$session, $ended];
            });

        // El estado central ya invalido las sesiones anteriores. La limpieza
        // fisica de tokens es best-effort y nunca vuelve a abrirlas si falla.
        $this->finalizeEnded($superadmin, $ended);

        return $session;
    }

    public function endLatest(User $superadmin, Tenant $tenant, ?string $sessionId = null): ?Impersonation
    {
        $ended = DB::connection($this->centralConnection())
            ->transaction(function () use ($superadmin, $tenant, $sessionId): Collection {
                $this->lockSuperadmin($superadmin);

                $query = Impersonation::query()
                    ->where('superadmin_id', $superadmin->id)
                    ->where('tenant_id', (string) $tenant->id)
                    ->whereNull('ended_at')
                    ->when($sessionId !== null, fn ($builder) => $builder->where('session_id', $sessionId))
                    ->latest('started_at')
                    ->limit(1)
                    ->lockForUpdate();

                $ended = $query->get();
                $this->markCollectionEnded($ended);

                return $ended;
            });

        $this->finalizeEnded($superadmin, $ended);

        return $ended->first();
    }

    /** @return Collection<int, Impersonation> */
    public function endAll(User $superadmin): Collection
    {
        $ended = DB::connection($this->centralConnection())
            ->transaction(function () use ($superadmin): Collection {
                $this->lockSuperadmin($superadmin);

                $ended = Impersonation::query()
                    ->where('superadmin_id', $superadmin->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->get();

                $this->markCollectionEnded($ended);

                return $ended;
            });

        $this->finalizeEnded($superadmin, $ended);

        return $ended;
    }

    /** @param Collection<int, Impersonation> $sessions */
    private function markCollectionEnded(Collection $sessions): void
    {
        if ($sessions->isEmpty()) {
            return;
        }

        $endedAt = Carbon::now('UTC');
        Impersonation::query()->whereKey($sessions->modelKeys())->update(['ended_at' => $endedAt]);
        $sessions->each(fn (Impersonation $session) => $session->forceFill(['ended_at' => $endedAt]));
    }

    /**
     * @param  Collection<int, Impersonation>  $sessions
     */
    private function finalizeEnded(User $superadmin, Collection $sessions): void
    {
        $sessions = $sessions
            ->filter(fn (Impersonation $session): bool => (int) $session->superadmin_id === (int) $superadmin->id)
            ->values();

        foreach ($sessions->groupBy('tenant_id') as $tenantId => $tenantSessions) {
            $tenant = Tenant::find((string) $tenantId);

            if ($tenant === null) {
                Log::warning('No se encontro el tenant al revocar una suplantacion terminada.', [
                    'tenant_id' => (string) $tenantId,
                    'session_ids' => $tenantSessions->pluck('session_id')->all(),
                ]);
            } else {
                try {
                    $this->inTenant($tenant, function () use ($superadmin, $tenant, $tenantSessions): void {
                        $shadows = User::withTrashed()
                            ->whereRaw('LOWER(email) = ?', [
                                strtolower(User::impersonationShadowEmail($superadmin->id)),
                            ])
                            ->get();
                        $tokenNames = $tenantSessions
                            ->map(fn (Impersonation $session): string => ImpersonationAccess::tokenName($session->session_id))
                            ->all();

                        foreach ($shadows as $shadow) {
                            $shadow->tokens()->whereIn('name', $tokenNames)->delete();

                            if (! $shadow->tokens()->exists()) {
                                $shadow->forceDelete();
                            }
                        }

                        foreach ($tenantSessions as $session) {
                            AuditLogger::tenant(
                                $superadmin,
                                'IMPERSONATE_STOP',
                                'sesion',
                                $session->session_id,
                                ['tenant_id' => (string) $tenant->id],
                                null,
                                $session->motivo,
                                $superadmin->email,
                            );
                        }
                    });
                } catch (Throwable $exception) {
                    // ended_at ya quedo persistido: el middleware rechaza el
                    // bearer aunque esta limpieza fisica deba reintentarse.
                    Log::warning('No se pudo eliminar un token sombra ya invalidado.', [
                        'tenant_id' => (string) $tenantId,
                        'session_ids' => $tenantSessions->pluck('session_id')->all(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            foreach ($tenantSessions as $session) {
                AuditLogger::platform(
                    $superadmin,
                    'IMPERSONATE_STOP',
                    'sesion',
                    $session->session_id,
                    ['tenant_id' => (string) $tenantId],
                    null,
                    $session->motivo,
                    (string) $tenantId,
                );
            }
        }
    }

    private function lockSuperadmin(User $superadmin): void
    {
        DB::connection($this->centralConnection())
            ->table($superadmin->getTable())
            ->where($superadmin->getKeyName(), $superadmin->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function inTenant(Tenant $tenant, Closure $callback): mixed
    {
        $original = function_exists('tenant') ? tenant() : null;

        try {
            tenancy()->initialize($tenant);

            return $callback();
        } finally {
            $original !== null ? tenancy()->initialize($original) : tenancy()->end();
        }
    }

    private function centralConnection(): string
    {
        return (string) config('tenancy.database.central_connection');
    }
}
