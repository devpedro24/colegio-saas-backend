<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rbac_permissions')->where('key', 'like', 'asistencia.%')
            ->update(['feature_key' => 'asistencia']);
        DB::table('rbac_permissions')->where('key', 'academico.preinformes.ver')
            ->update(['feature_key' => 'preinformes']);
    }

    public function down(): void
    {
        DB::table('rbac_permissions')->where('key', 'like', 'asistencia.%')
            ->where('feature_key', 'asistencia')->update(['feature_key' => null]);
        DB::table('rbac_permissions')->where('key', 'academico.preinformes.ver')
            ->where('feature_key', 'preinformes')->update(['feature_key' => null]);
    }
};
