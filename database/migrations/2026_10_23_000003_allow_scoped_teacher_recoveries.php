<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['docente', 'director_grupo'] as $role) {
            // Do not overwrite an existing central configuration.
            DB::table('rbac_matrix')->insertOrIgnore([
                'role_key' => $role, 'permission_key' => 'notas.gestionar_nivelaciones',
                'type' => 'configurable', 'level' => null, 'default_granted' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
