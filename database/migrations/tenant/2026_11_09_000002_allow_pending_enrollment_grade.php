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
            $table->unsignedBigInteger('grado_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('ingreso_solicitudes')->whereNull('grado_id')->exists()) {
            throw new RuntimeException('Completa los grados pendientes antes de revertir esta migración.');
        }
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->unsignedBigInteger('grado_id')->nullable(false)->change();
        });
    }
};
