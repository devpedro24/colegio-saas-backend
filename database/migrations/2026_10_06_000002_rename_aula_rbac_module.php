<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Solo el rótulo antiguo generado por la primera migración; no tocar
        // permisos ajenos ni decisiones configurables de cada colegio.
        DB::table('rbac_permissions')->where('key', 'like', 'aula.%')
            ->where('module', 'Aula por Asignatura')
            ->update(['module' => 'Aula', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // El nombre visible puede haber sido editado posteriormente.
    }
};
