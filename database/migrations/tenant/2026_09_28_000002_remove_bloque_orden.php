<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bloques_horarios') || ! Schema::hasColumn('bloques_horarios', 'orden')) {
            return;
        }

        Schema::table('bloques_horarios', function (Blueprint $table): void {
            $table->dropColumn('orden');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bloques_horarios') || Schema::hasColumn('bloques_horarios', 'orden')) {
            return;
        }

        Schema::table('bloques_horarios', function (Blueprint $table): void {
            $table->unsignedTinyInteger('orden')->default(0);
        });
    }
};
