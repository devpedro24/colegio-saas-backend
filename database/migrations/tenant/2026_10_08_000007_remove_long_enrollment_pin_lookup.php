<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ingreso_solicitudes', 'pin_lookup')) {
            return;
        }
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn('pin_lookup');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ingreso_solicitudes', 'pin_lookup')) {
            return;
        }
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->char('pin_lookup', 64)->nullable()->unique();
        });
    }
};
