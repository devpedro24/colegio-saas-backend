<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grados', function (Blueprint $table): void {
            $table->boolean('es_terminal')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('grados', function (Blueprint $table): void {
            $table->dropColumn('es_terminal');
        });
    }
};
