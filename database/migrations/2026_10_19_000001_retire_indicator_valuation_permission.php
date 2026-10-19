<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_matrix')->where('permission_key', 'indicadores.valoraciones.registrar')->delete();
        DB::table('rbac_permissions')->where('key', 'indicadores.valoraciones.registrar')->delete();
    }

    public function down(): void
    {
        // Retired capability; restoring it requires an explicit product decision.
    }
};
