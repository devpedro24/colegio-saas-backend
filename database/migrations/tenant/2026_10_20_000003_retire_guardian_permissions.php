<?php

declare(strict_types=1);

use App\Rbac\PermissionPlanFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', PermissionPlanFeature::RETIRED_GUARDIAN_PERMISSIONS)
            ->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Un permiso retirado no debe reaparecer al revertir una migración.
    }
};
