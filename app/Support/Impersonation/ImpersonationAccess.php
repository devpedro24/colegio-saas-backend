<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Support\Str;

/** Valida y resuelve una sesion de suplantacion a partir del token Sanctum. */
final class ImpersonationAccess
{
    public const TOKEN_PREFIX = 'impersonation:';

    /** @var array<string, Impersonation|null> */
    private array $cache = [];

    public function sessionFor(User $user): ?Impersonation
    {
        if ($user->status !== User::STATUS_ACTIVE || $user->trashed()) {
            return null;
        }

        $token = $user->currentAccessToken();
        $sessionId = $this->sessionIdFromToken($token);

        if ($sessionId === null) {
            return null;
        }

        $tenantId = function_exists('tenant') ? tenant()?->getKey() : null;
        if ($tenantId === null) {
            return null;
        }

        $cacheKey = $sessionId.'|'.(string) $tenantId.'|'.$user->getKey();

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $session = Impersonation::query()
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->where('expires_at', '>', now('UTC'))
            ->where('tenant_id', (string) $tenantId)
            ->first();

        if ($session === null
            || ! hash_equals(User::impersonationShadowEmail($session->superadmin_id), (string) $user->email)) {
            return $this->cache[$cacheKey] = null;
        }

        return $this->cache[$cacheKey] = $session;
    }

    public function sessionIdFromToken(?object $token): ?string
    {
        $name = is_string($token?->name ?? null) ? $token->name : '';

        if (! str_starts_with($name, self::TOKEN_PREFIX)
            || ! method_exists($token, 'can')
            || ! $token->can('impersonate')) {
            return null;
        }

        $sessionId = substr($name, strlen(self::TOKEN_PREFIX));

        return Str::isUuid($sessionId) ? $sessionId : null;
    }

    /**
     * Distingue tokens de suplantacion sin confundir tokens normales legacy
     * con ability comodin (`*`). El nombre tambien se valida para rechazar un
     * token de suplantacion malformado aunque haya perdido su ability explicita.
     */
    public function isImpersonationToken(?object $token): bool
    {
        $name = is_string($token?->name ?? null) ? $token->name : '';
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];

        return str_starts_with($name, self::TOKEN_PREFIX)
            || in_array('impersonate', $abilities, true);
    }

    public static function tokenName(string $sessionId): string
    {
        return self::TOKEN_PREFIX.$sessionId;
    }
}
