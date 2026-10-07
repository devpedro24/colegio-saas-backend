<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['estandar', 'premium'] as $key) {
            $plan = Plan::where('key', $key)->first();
            if ($plan && ! in_array('aula_colores', $plan->features ?? [], true)) {
                $plan->features = [...($plan->features ?? []), 'aula_colores'];
                $plan->save();
            }
        }

        DB::table('rbac_matrix')->where('permission_key', 'aula.configurar')
            ->where('role_key', '!=', 'rector')->delete();
        $entry = collect(PermissionMatrix::permissions())->firstWhere('key', 'aula.apariencia.configurar');
        DB::table('rbac_permissions')->insertOrIgnore([
            'key' => $entry['key'], 'module' => $entry['module'], 'action' => $entry['action'],
            'feature_key' => 'aula_colores', 'is_system' => true,
            'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rbac_matrix')->insertOrIgnore([
            'role_key' => 'rector', 'permission_key' => $entry['key'],
            'type' => 'structural', 'level' => 'editar', 'default_granted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
