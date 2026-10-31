<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_permissions')->insertOrIgnore([
            'key' => 'indicadores.configurar', 'module' => 'Indicadores',
            'action' => 'Configurar el alcance por grado o grupo y la edición docente',
            'feature_key' => 'academico', 'is_system' => true,
            'sort_order' => (int) DB::table('rbac_permissions')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['rector' => ['structural', 'editar', true],
            'coord_academico' => ['configurable', null, false],
            'coord_combinado' => ['configurable', null, false],
            'secretaria' => ['configurable', null, false]] as $role => [$type, $level, $default]) {
            DB::table('rbac_matrix')->insertOrIgnore([
                'role_key' => $role, 'permission_key' => 'indicadores.configurar',
                'type' => $type, 'level' => $level, 'default_granted' => $default,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('rbac_permissions')->where('key', 'indicadores.base.gestionar')
            ->update(['action' => 'Gestionar indicadores por grado o grupo']);
        DB::table('rbac_permissions')->where('key', 'indicadores.grupo.editar')
            ->update(['action' => 'Crear y editar indicadores de materias y grupos asignados']);
    }

    public function down(): void
    {
        DB::table('rbac_matrix')->where('permission_key', 'indicadores.configurar')->delete();
        DB::table('rbac_permissions')->where('key', 'indicadores.configurar')->delete();
    }
};
