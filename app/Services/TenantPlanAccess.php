<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomRole;
use App\Models\Plan;
use App\Models\Rbac\RbacPermission;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionPlanFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Autorización efectiva: plan vigente + roles del colegio + cupo de roles adicionales. */
final class TenantPlanAccess
{
    private ?array $permissionFeatures = null;

    private ?Request $contextRequest = null;

    private ?string $contextTenant = null;

    private ?array $effectiveCustomRoleKeys = null;

    private ?string $customRoleTenant = null;

    private ?string $planTenant = null;

    private ?Plan $currentPlan = null;

    /** @var array<string, list<string>> */
    private array $effectivePermissionCache = [];

    public function plan(): ?Plan
    {
        $this->refreshContext();
        $school = tenant();
        if (! $school) {
            return null;
        }

        $tenantKey = (string) $school->getTenantKey();
        if ($this->planTenant === $tenantKey) {
            return $this->currentPlan;
        }

        $owner = $school->parent_id
            ? Tenant::on(config('tenancy.database.central_connection'))->find($school->parent_id)
            : $school;

        $this->planTenant = $tenantKey;

        return $this->currentPlan = $owner ? SchoolSubscription::effective($owner->fresh()) : null;
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->plan()?->features ?? [];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** null means unlimited; no valid plan is fail-closed. */
    public function customRoleLimit(): ?int
    {
        $plan = $this->plan();

        return $plan ? $plan->max_roles_personalizados : 0;
    }

    /** @return list<string> */
    public function effectiveCustomRoleKeys(): array
    {
        $this->refreshContext();
        $tenantKey = (string) tenant()?->getTenantKey();
        if ($this->customRoleTenant === $tenantKey && $this->effectiveCustomRoleKeys !== null) {
            return $this->effectiveCustomRoleKeys;
        }
        $this->customRoleTenant = $tenantKey;
        $this->effectivePermissionCache = [];
        if (! Schema::hasTable('custom_roles')) {
            return $this->effectiveCustomRoleKeys = [];
        }

        $query = CustomRole::query()->where('is_active', true)->orderBy('created_at')->orderBy('key');
        $limit = $this->customRoleLimit();
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $this->effectiveCustomRoleKeys = $query->pluck('key')->all();
    }

    public function customRoleIsEffective(string $key): bool
    {
        return in_array($key, $this->effectiveCustomRoleKeys(), true);
    }

    /** @return list<string> */
    public function effectivePermissions(User $user): array
    {
        $this->refreshContext();
        if (! tenancy()->initialized) {
            return [];
        }

        $cacheKey = (string) tenant()?->getTenantKey().'|'.$user->getKey();
        if (isset($this->effectivePermissionCache[$cacheKey])) {
            return $this->effectivePermissionCache[$cacheKey];
        }

        $features = $this->features();
        $permissionFeatures = $this->permissionFeatures();
        $grants = $user->getDirectPermissions()->pluck('name')->all();
        $allowedCustom = $this->effectiveCustomRoleKeys();

        foreach ($user->roles()->with('permissions:id,name')->get() as $role) {
            if (str_starts_with($role->name, 'custom_') && ! in_array($role->name, $allowedCustom, true)) {
                continue;
            }
            foreach ($role->permissions as $permission) {
                $grants[] = $permission->name;
            }
        }

        return $this->effectivePermissionCache[$cacheKey] = array_values(array_filter(array_unique($grants), static function (string $key) use ($permissionFeatures, $features): bool {
            if (in_array($key, PermissionPlanFeature::RETIRED_GUARDIAN_PERMISSIONS, true)) {
                return false;
            }
            $feature = $permissionFeatures[$key] ?? null;

            return $feature !== null && in_array($feature, $features, true);
        }));
    }

    public function forget(): void
    {
        $this->effectiveCustomRoleKeys = null;
        $this->effectivePermissionCache = [];
        $this->planTenant = null;
        $this->currentPlan = null;
    }

    /** null for non-catalog gates, so Laravel's normal policies still apply. */
    public function catalogPermission(User $user, string $ability): ?bool
    {
        if (in_array($ability, PermissionPlanFeature::RETIRED_GUARDIAN_PERMISSIONS, true)) {
            return false;
        }
        if (! tenancy()->initialized) {
            return null;
        }

        // Un permiso huérfano del catálogo central no puede saltarse el plan
        // por conservar una concesión vieja dentro de Spatie.
        if (! array_key_exists($ability, $this->permissionFeatures())) {
            return false;
        }

        return in_array($ability, $this->effectivePermissions($user), true);
    }

    /** @return array<string, string|null> */
    private function permissionFeatures(): array
    {
        $this->refreshContext();
        if ($this->permissionFeatures === null) {
            $this->permissionFeatures = RbacPermission::query()->pluck('feature_key', 'key')->all();
        }

        return $this->permissionFeatures;
    }

    private function refreshContext(): void
    {
        $currentRequest = app()->bound('request') ? request() : null;
        $tenantKey = tenant() ? (string) tenant()->getTenantKey() : null;
        if ($this->contextRequest === $currentRequest && $this->contextTenant === $tenantKey) {
            return;
        }
        $this->contextRequest = $currentRequest;
        $this->contextTenant = $tenantKey;
        $this->permissionFeatures = null;
        $this->effectiveCustomRoleKeys = null;
        $this->customRoleTenant = null;
        $this->planTenant = null;
        $this->currentPlan = null;
        $this->effectivePermissionCache = [];
    }
}
