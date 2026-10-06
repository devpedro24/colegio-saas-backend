<?php

declare(strict_types=1);

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'asistencia.configurar_politica',
        'asistencia.justificar_propia',
        'asistencia.correccion.solicitar',
        'asistencia.correccion.aprobar',
    ];

    public function up(): void
    {
        $order = (int) DB::table('rbac_permissions')->max('sort_order') + 1;
        foreach (PermissionMatrix::permissions() as $entry) {
            if (! in_array($entry['key'], self::KEYS, true)) {
                continue;
            }
            DB::table('rbac_permissions')->insertOrIgnore([
                'key' => $entry['key'], 'module' => $entry['module'], 'action' => $entry['action'],
                'feature_key' => 'asistencia', 'is_system' => true, 'sort_order' => $order++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($entry['cells'] as $role => $value) {
                $cell = PermissionMatrix::classifyCell($value);
                DB::table('rbac_matrix')->insertOrIgnore([
                    'role_key' => $role, 'permission_key' => $entry['key'],
                    'type' => $cell['type'], 'level' => $cell['level'],
                    'default_granted' => $cell['default'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Conservar decisiones de RBAC y auditoría: una reversión de esquema no borra permisos.
    }
};
