<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_roles_personalizados')->nullable()->default(0);
        });

        DB::table('plans')->where('key', 'estandar')->update(['max_roles_personalizados' => 2]);
        DB::table('plans')->where('key', 'premium')->update(['max_roles_personalizados' => null]);
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('max_roles_personalizados'));
    }
};
