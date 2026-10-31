<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_permissions')->where('key', 'indicadores.grupo.editar')
            ->where('action', 'Editar variantes solo en materia y grupo asignados')
            ->update(['action' => 'Crear indicadores y adaptar variantes en materias y grupos autorizados']);
    }

    public function down(): void
    {
        DB::table('rbac_permissions')->where('key', 'indicadores.grupo.editar')
            ->where('action', 'Crear indicadores y adaptar variantes en materias y grupos autorizados')
            ->update(['action' => 'Editar variantes solo en materia y grupo asignados']);
    }
};
