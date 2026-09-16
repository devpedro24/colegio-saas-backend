<?php

declare(strict_types=1);

namespace App\Support\Mfa;

use App\Models\User;
use App\Support\Impersonation\ImpersonationAccess;

final class MfaPolicy
{
    public function __construct(private readonly ImpersonationAccess $impersonation) {}

    /** Determina si el usuario debe tener TOTP confirmado para operar. */
    public function isRequired(User $user): bool
    {
        // La identidad sombra ya deriva de una sesion de plataforma que paso por
        // el MFA obligatorio del superadmin y usa un token corto y verificable.
        if ($this->impersonation->sessionFor($user) !== null) {
            return false;
        }

        if (! (function_exists('tenant') && tenant() !== null)) {
            return (bool) config('security.mfa.required_for_platform', true);
        }

        $required = (array) config('security.mfa.required_tenant_roles', []);
        $roles = collect([$user->role])
            ->merge($user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->getRoleNames())
            ->filter()
            ->unique();

        return $roles->intersect($required)->isNotEmpty();
    }
}
