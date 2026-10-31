<?php

declare(strict_types=1);

use App\Plans\PlanCatalog;
use App\Rbac\PermissionPlanFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $retired = array_values(array_unique([
                ...PermissionPlanFeature::RETIRED_GUARDIAN_PERMISSIONS,
                ...DB::table('rbac_permissions')->where('feature_key', 'app_acudientes')->pluck('key')->all(),
            ]));
            DB::table('rbac_matrix')->whereIn('permission_key', $retired)->delete();
            DB::table('rbac_permissions')->whereIn('key', $retired)->delete();

            foreach (DB::table('rbac_permissions')->get(['key', 'feature_key']) as $permission) {
                $previous = $permission->feature_key;
                $feature = $permission->key === 'portal.acceder' || $previous === null
                    ? PermissionPlanFeature::forKey($permission->key)
                    : $previous;
                if ($feature !== $previous) {
                    DB::table('rbac_permissions')->where('key', $permission->key)
                        ->update(['feature_key' => $feature, 'updated_at' => now()]);
                }
            }

            foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
                $features = json_decode((string) $plan->features, true, 512, JSON_THROW_ON_ERROR);
                $updated = array_values(array_unique([
                    ...PlanCatalog::requiredFeatureKeys(),
                    ...array_values(array_diff($features, ['app_acudientes'])),
                ]));
                if ($updated !== $features) {
                    DB::table('plans')->where('id', $plan->id)
                        ->update(['features' => json_encode($updated, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Las selecciones históricas de planes y los permisos retirados no se
        // pueden reconstruir sin adivinar decisiones del colegio.
    }
};
