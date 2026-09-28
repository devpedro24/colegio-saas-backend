<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['grados', 'niveles'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'orden')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('orden');
            });
        }
    }

    public function down(): void
    {
        foreach (['niveles', 'grados'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'orden')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedTinyInteger('orden')->default(0);
            });
        }
    }
};
