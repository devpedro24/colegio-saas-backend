<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_configuracion', fn (Blueprint $table) => $table->text('politica_datos')->nullable());
    }

    public function down(): void
    {
        Schema::table('ingreso_configuracion', fn (Blueprint $table) => $table->dropColumn('politica_datos'));
    }
};
