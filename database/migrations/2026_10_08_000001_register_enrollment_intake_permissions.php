<?php

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionMatrix::permissions() as $entry) {
            if (! str_starts_with($entry['key'], 'ingreso.')) {
                continue;
            }
            DB::table('rbac_permissions')->insertOrIgnore(['key' => $entry['key'], 'module' => $entry['module'],
                'action' => $entry['action'], 'feature_key' => 'academico', 'is_system' => true,
                'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($entry['cells'] as $role => $value) {
                $cell = PermissionMatrix::classifyCell($value);
                DB::table('rbac_matrix')->insertOrIgnore(['role_key' => $role, 'permission_key' => $entry['key'],
                    'type' => $cell['type'], 'level' => $cell['level'], 'default_granted' => $cell['default'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('rbac_roles')->where('key', 'estudiante')->where('label', 'Estudiante / Acudiente')->update(['label' => 'Estudiante']);
    }

    public function down(): void {} // Do not discard configured role grants on rollback.
};
