<?php

declare(strict_types=1);

namespace App\Support\Account;

use App\Models\Plan;
use App\Models\User;

final class AccountPresenter
{
    public static function capabilities(User $user): array
    {
        // La identidad académica del estudiante se administra desde el colegio.
        $student = tenancy()->initialized && $user->hasRole('estudiante');

        return ['edit_name' => ! $student, 'edit_email' => ! $student, 'edit_phone' => true,
            'google_link' => (bool) config('services.google.client_id') && ! $student];
    }

    public static function institution(): ?array
    {
        $school = tenant();
        if (! $school) {
            return null;
        }
        $owner = $school->parent_id ? $school->parent : $school;
        $plan = $owner ? Plan::where('key', $owner->plan)->first() : null;

        return ['name' => $school->name, 'plan' => $plan ? ['key' => $plan->key, 'name' => $plan->name] : null];
    }

    public static function user(User $user): array
    {
        $tenant = tenancy()->initialized;

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'google_email' => $user->google_email,
            'email_verified' => $user->email_verified_at !== null,
            'tenant_id' => tenant()?->getKey(),
            'must_change_password' => (bool) $user->must_change_password,
            'mfa_enabled' => $user->hasTwoFactorEnabled(),
            'mfa_required' => false,
            'roles' => $tenant ? $user->getRoleNames()->values() : [$user->role],
            'permissions' => $tenant ? $user->getAllPermissions()->pluck('name')->values() : [],
            'is_platform' => ! $tenant, 'is_superadmin' => $user->esSuperadminPlataforma(),
            'profile_capabilities' => self::capabilities($user),
            'institution' => self::institution(),
        ];
    }
}
