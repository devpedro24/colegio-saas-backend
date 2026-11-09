<?php

declare(strict_types=1);

namespace App\Support\Account;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SchoolSubscription;
use App\Services\SedeAccess;
use App\Services\TenantPlanAccess;
use App\Support\PublicIdentityToken;
use App\Support\Realtime\TenantChannelName;

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
        $owner = $school->parent_id
            ? Tenant::on(config('tenancy.database.central_connection'))->find($school->parent_id)
            : $school;
        $plan = $owner ? SchoolSubscription::effective($owner) : null;

        return ['name' => $school->name, 'plan' => $plan ? ['key' => $plan->key, 'name' => $plan->name,
            'features' => $plan->features ?? []] : null];
    }

    public static function user(User $user): array
    {
        $tenant = tenancy()->initialized;
        $source = $tenant && $user->school_user_id
            ? app(SedeAccess::class)->source($user->email, (int) $user->school_user_id) : null;

        return [
            'id' => PublicIdentityToken::forUser($user), 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'google_email' => ($source ?? $user)->google_email,
            'email_verified' => ($source ?? $user)->email_verified_at !== null,
            'tenant_channel' => tenant() ? TenantChannelName::tokenForId((string) tenant()->getTenantKey()) : null,
            'must_change_password' => (bool) $user->must_change_password,
            'mfa_enabled' => ($source ?? $user)->hasTwoFactorEnabled(),
            'mfa_required' => false,
            'roles' => $tenant ? $user->getRoleNames()->values() : [$user->role],
            'permissions' => $tenant ? app(TenantPlanAccess::class)->effectivePermissions($user) : [],
            'is_platform' => ! $tenant, 'is_superadmin' => $user->esSuperadminPlataforma(),
            'profile_capabilities' => self::capabilities($user),
            'institution' => self::institution(),
        ];
    }
}
