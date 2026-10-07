<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('aula_politicas', function (Blueprint $table): void {
            $table->json('colores_periodos')->nullable();
            $table->string('color_preinforme', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aula_politicas', function (Blueprint $table): void {
            $table->dropColumn(['colores_periodos', 'color_preinforme']);
        });
    }
};
