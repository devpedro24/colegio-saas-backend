<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->char('access_lookup', 64)->nullable()->unique();
            $table->unsignedTinyInteger('pin_intentos')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->dropUnique(['access_lookup']);
            $table->dropColumn(['access_lookup', 'pin_intentos']);
        });
    }
};
