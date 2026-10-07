<?php

declare(strict_types=1);

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $entry = collect(PermissionMatrix::permissions())->firstWhere('key', 'aula.contenido.restaurar');
        DB::table('rbac_permissions')->insertOrIgnore([
            'key' => $entry['key'], 'module' => $entry['module'], 'action' => $entry['action'],
            'feature_key' => 'aula', 'is_system' => true,
            'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($entry['cells'] as $role => $value) {
            $cell = PermissionMatrix::classifyCell($value);
            DB::table('rbac_matrix')->insertOrIgnore([
                'role_key' => $role, 'permission_key' => $entry['key'], 'type' => $cell['type'],
                'level' => $cell['level'], 'default_granted' => $cell['default'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
