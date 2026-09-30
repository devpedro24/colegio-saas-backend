<?php

declare(strict_types=1);

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'academico.matriculas.gestionar', 'eventos.gestionar', 'eventos.configurar',
        'eventos.publicar_institucional', 'eventos.publicar_asignados',
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
                'feature_key' => null, 'is_system' => true, 'sort_order' => $order++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($entry['cells'] as $role => $cell) {
                $policy = PermissionMatrix::classifyCell($cell);
                if ($policy['type'] === 'denied') {
                    continue;
                }
                DB::table('rbac_matrix')->insertOrIgnore([
                    'role_key' => $role, 'permission_key' => $entry['key'],
                    'type' => $policy['type'], 'level' => $policy['level'],
                    'default_granted' => $policy['default'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('rbac_matrix')->whereIn('permission_key', self::KEYS)->delete();
        DB::table('rbac_permissions')->whereIn('key', self::KEYS)->delete();
    }
};
