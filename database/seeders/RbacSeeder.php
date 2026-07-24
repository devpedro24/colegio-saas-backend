<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra/sincroniza el RBAC de spatie DENTRO de la BD de un colegio (tenant)
 * a partir del catalogo CENTRAL (rbac_*) filtrado por el PLAN del colegio.
 *
 * Reglas:
 *  - Crea las filas de spatie (roles/permisos) que falten.
 *  - Otorga los estructurales que el plan permite; revoca los bloqueados por plan
 *    o denegados (gating).
 *  - Configurables: en la primera siembra (o cuando el permiso es nuevo para el
 *    tenant) aplica el default; en re-sync PRESERVA la eleccion del rector.
 *
 * `firstSeed=true` fuerza aplicar defaults (provisioning); `false` = re-sync.
 */
class RbacSeeder extends Seeder
{
    public function __construct(private bool $firstSeed = false) {}

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Features del plan del colegio (para el gating).
        $planKey = tenant()?->plan;
        $planFeatures = $planKey ? (Plan::where('key', $planKey)->value('features') ?? []) : [];
        $planFeatures = is_array($planFeatures) ? $planFeatures : [];

        // Catalogo central.
        $permissions = RbacPermission::all();
        $roles = RbacRole::all();
        $cells = RbacMatrixCell::all()->groupBy('role_key');

        // 1. Asegura permisos de spatie; recuerda cuales nacen ahora en este tenant.
        $featureOf = [];
        $isNewPermission = [];
        foreach ($permissions as $perm) {
            $featureOf[$perm->key] = $perm->feature_key;
            $model = Permission::findOrCreate($perm->key, 'web');
            if ($model->wasRecentlyCreated) {
                $isNewPermission[$perm->key] = true;
            }
        }

        // 2. Asegura roles y aplica grants segun matriz + plan.
        foreach ($roles as $rbacRole) {
            $role = Role::findOrCreate($rbacRole->key, 'web');
            $roleCells = ($cells[$rbacRole->key] ?? collect())->keyBy('permission_key');
            $granted = $role->permissions()->pluck('name')->flip(); // name => idx

            foreach ($permissions as $perm) {
                $key = $perm->key;
                $has = isset($granted[$key]);
                $feature = $featureOf[$key];
                $planAllowed = $feature === null || in_array($feature, $planFeatures, true);
                $cell = $roleCells[$key] ?? null; // null = denegado
                $isNew = $this->firstSeed || isset($isNewPermission[$key]);

                // Bloqueado por plan o denegado -> revocar.
                if (! $planAllowed || $cell === null) {
                    if ($has) {
                        $role->revokePermissionTo($key);
                    }
                    continue;
                }

                if ($cell->type === 'structural') {
                    if (! $has) {
                        $role->givePermissionTo($key);
                    }
                    continue;
                }

                // Configurable: aplicar default solo si es nuevo; si no, preservar.
                if ($isNew) {
                    if ($cell->default_granted && ! $has) {
                        $role->givePermissionTo($key);
                    } elseif (! $cell->default_granted && $has) {
                        $role->revokePermissionTo($key);
                    }
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
