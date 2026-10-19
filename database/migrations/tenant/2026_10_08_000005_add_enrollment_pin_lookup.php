<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->char('pin_lookup', 64)->nullable()->unique();
            $table->timestamp('pin_expira')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('ingreso_solicitudes')->whereNull('pin_expira')->exists()) {
            throw new RuntimeException('Hay PIN permanentes. No se puede revertir esta migración sin definir su vencimiento.');
        }
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn('pin_lookup');
            $table->timestamp('pin_expira')->nullable(false)->change();
        });
    }
};
