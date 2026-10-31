<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['docente', 'director_grupo'] as $role) {
            DB::table('rbac_matrix')->insertOrIgnore([
                'role_key' => $role,
                'permission_key' => 'boletines.observaciones.gestionar',
                'type' => 'configurable',
                'level' => null,
                'default_granted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
