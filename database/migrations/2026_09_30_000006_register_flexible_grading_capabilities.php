<?php

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->whereIn('key', ['estandar', 'premium'])->get() as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode(array_values(array_unique([...$features, 'preinformes'])))]);
        }
        $order = (int) DB::table('rbac_permissions')->max('sort_order') + 1;
        foreach (PermissionMatrix::permissions() as $entry) {
            if (! str_starts_with($entry['key'], 'academico.preinformes.') && ! str_starts_with($entry['key'], 'notas.actividades.') && $entry['key'] !== 'notas.planilla.configurar') {
                continue;
            }
            DB::table('rbac_permissions')->insertOrIgnore(['key' => $entry['key'], 'module' => $entry['module'], 'action' => $entry['action'],
                'feature_key' => $entry['feature'] ?? null, 'is_system' => true, 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($entry['cells'] as $role => $cell) {
                $policy = PermissionMatrix::classifyCell($cell);
                DB::table('rbac_matrix')->insertOrIgnore(['role_key' => $role, 'permission_key' => $entry['key'], 'type' => $policy['type'],
                    'level' => $policy['level'], 'default_granted' => $policy['default'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
