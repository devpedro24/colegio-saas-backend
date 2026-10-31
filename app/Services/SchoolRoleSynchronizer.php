<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomRole;
use App\Models\Rbac\RbacRole;
use App\Models\Tenant;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Copia la matriz del colegio principal a las BD aisladas de sus sedes. */
final class SchoolRoleSynchronizer
{
    public function hasChildAssignments(string $roleKey): bool
    {
        foreach ($this->children() as $child) {
            if ($this->inChild($child, static function () use ($roleKey): bool {
                $role = Role::where('name', $roleKey)->where('guard_name', 'web')->first();

                return $role !== null && DB::table('model_has_roles')
                    ->where('role_id', $role->getKey())->exists();
            })) {
                return true;
            }
        }

        return false;
    }

    /** La sede no modifica el catálogo: hereda permisos, roles y nombres. */
    public function synchronize(?string $changedRole = null): void
    {
        $custom = CustomRole::query()->orderBy('created_at')->orderBy('key')->get()
            ->map(fn (CustomRole $role) => [
                'key' => $role->key, 'label' => $role->label,
                'is_active' => $role->is_active, 'created_at' => $role->created_at,
            ])->all();
        $names = array_merge(RbacRole::query()->pluck('key')->all(), array_column($custom, 'key'));
        $toSync = $changedRole === null ? $names : array_values(array_intersect($names, [$changedRole]));
        $grants = Role::query()->with('permissions:id,name')->whereIn('name', $toSync)->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->all()])->all();

        foreach ($this->children() as $child) {
            $this->inChild($child, function () use ($custom, $toSync, $grants, $child): void {
                foreach ($custom as $data) {
                    $entry = CustomRole::firstOrNew(['key' => $data['key']]);
                    $entry->label = $data['label'];
                    $entry->is_active = $data['is_active'];
                    $entry->created_at = $data['created_at'];
                    if (! $entry->exists || $entry->isDirty()) {
                        $entry->save();
                    }
                }
                foreach ($toSync as $name) {
                    $role = Role::findOrCreate($name, 'web');
                    $permissions = array_map(static fn (string $key) => Permission::findOrCreate($key, 'web'), $grants[$name] ?? []);
                    $role->syncPermissions($permissions);
                }
                $removed = CustomRole::query()->whereNotIn('key', array_column($custom, 'key'))->get();
                foreach ($removed as $entry) {
                    $role = Role::where('name', $entry->key)->where('guard_name', 'web')->first();
                    if ($role) {
                        abort_if(DB::table('model_has_roles')->where('role_id', $role->getKey())->exists(), 409,
                            'El rol aún está asignado en una sede.');
                        $role->delete();
                    }
                    $entry->delete();
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(TenantPlanAccess::class)->forget();
                app(RealtimeChanges::class)->record((string) $child->getTenantKey(), 'rbac');
            });
        }
    }

    /** @return Collection<int, Tenant> */
    private function children(): Collection
    {
        $school = tenant();
        if (! $school || $school->parent_id) {
            return new Collection;
        }

        return Tenant::query()->where('parent_id', $school->getTenantKey())
            ->whereNotIn('status', [Tenant::STATUS_PROVISIONING, Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])
            ->get();
    }

    public function inChild(Tenant $child, callable $callback): mixed
    {
        $school = tenant();
        tenancy()->end();
        try {
            return $child->run($callback);
        } finally {
            tenancy()->initialize($school);
        }
    }
}
