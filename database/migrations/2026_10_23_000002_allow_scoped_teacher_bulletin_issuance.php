<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['boletines.plantillas.ver', 'boletines.emisiones.ver', 'boletines.emisiones.preparar', 'boletines.emisiones.publicar'] as $permission) {
            foreach (['docente', 'director_grupo'] as $role) {
                DB::table('rbac_matrix')->insertOrIgnore(['role_key' => $role, 'permission_key' => $permission,
                    'type' => 'configurable', 'level' => null, 'default_granted' => true,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('rbac_permissions')->where('key', 'boletines.emisiones.preparar')
            ->update(['action' => 'Generar boletines y archivar sus PDF', 'updated_at' => now()]);
    }

    public function down(): void {}
};
