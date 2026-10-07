<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // An Aula now follows group + curriculum; manual creation is not an action.
        DB::table('rbac_matrix')->where('permission_key', 'aula.aulas.crear')->delete();
        DB::table('rbac_permissions')->where('key', 'aula.aulas.crear')->delete();
    }

    public function down(): void
    {
        // Do not resurrect a permission without an endpoint or UI action.
    }
};
