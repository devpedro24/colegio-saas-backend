<?php

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_permissions')->insertOrIgnore(['key' => 'config.correo', 'module' => 'Configuracion del Colegio',
            'action' => 'Configurar el correo remitente del colegio', 'feature_key' => null, 'is_system' => true,
            'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (PermissionMatrix::roleKeys() as $role) {
            DB::table('rbac_matrix')->insertOrIgnore(['role_key' => $role, 'permission_key' => 'config.correo',
                'type' => $role === 'rector' ? 'structural' : 'denied', 'level' => $role === 'rector' ? 'editar' : null,
                'default_granted' => $role === 'rector', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
