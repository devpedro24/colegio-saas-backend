<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_campanas', function (Blueprint $table) {
            $table->date('hasta')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never invent a closing date or delete an open-ended campaign on rollback.
        if (DB::table('ingreso_campanas')->whereNull('hasta')->exists()) {
            throw new RuntimeException('Hay convocatorias sin fecha de cierre. Define sus fechas antes de revertir esta migración.');
        }
        Schema::table('ingreso_campanas', function (Blueprint $table) {
            $table->date('hasta')->nullable(false)->change();
        });
    }
};
