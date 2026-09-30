<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodos', function (Blueprint $table): void {
            // Una reapertura excepcional sigue vigente hasta que el rector la cierre.
            $table->boolean('reapertura_manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('periodos', fn (Blueprint $table) => $table->dropColumn('reapertura_manual'));
    }
};
