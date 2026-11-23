<?php

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = ['eventos.correos.revisar', 'aula.correos.revisar'];

    public function up(): void
    {
        foreach (collect(PermissionMatrix::permissions())->whereIn('key', self::KEYS) as $entry) {
            DB::table('rbac_permissions')->insertOrIgnore(['key' => $entry['key'], 'module' => $entry['module'],
                'action' => $entry['action'], 'feature_key' => $entry['feature'], 'is_system' => true,
                'sort_order' => DB::table('rbac_permissions')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($entry['cells'] as $role => $value) {
                $cell = PermissionMatrix::classifyCell($value);
                DB::table('rbac_matrix')->insertOrIgnore(['permission_key' => $entry['key'], 'role_key' => $role,
                    'type' => $cell['type'], 'level' => $cell['level'], 'default_granted' => $cell['default'],
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('rbac_matrix')->whereIn('permission_key', self::KEYS)->delete();
        DB::table('rbac_permissions')->whereIn('key', self::KEYS)->delete();
    }
};
