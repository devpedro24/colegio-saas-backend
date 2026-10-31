<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_permissions')->insertOrIgnore([
            'key' => 'pagos.ver', 'module' => 'Pagos', 'action' => 'Consultar el módulo de pagos',
            'feature_key' => 'pagos_pension', 'is_system' => true,
            'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['rector' => ['structural', 'ver', true],
            'secretaria' => ['configurable', null, false]] as $role => [$type, $level, $default]) {
            DB::table('rbac_matrix')->insertOrIgnore([
                'role_key' => $role, 'permission_key' => 'pagos.ver',
                'type' => $type, 'level' => $level, 'default_granted' => $default,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('rbac_matrix')->where('permission_key', 'pagos.ver')->delete();
        DB::table('rbac_permissions')->where('key', 'pagos.ver')->delete();
    }
};
