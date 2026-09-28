<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sesiones_horario')) {
            return;
        }

        Schema::table('sesiones_horario', function (Blueprint $table): void {
            if (! Schema::hasColumn('sesiones_horario', 'hora_inicio')) {
                $table->time('hora_inicio')->nullable();
            }
            if (! Schema::hasColumn('sesiones_horario', 'hora_fin')) {
                $table->time('hora_fin')->nullable();
            }
            $table->foreignId('bloque_horario_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sesiones_horario')) {
            return;
        }
        if (DB::table('sesiones_horario')->whereNull('bloque_horario_id')->exists()) {
            throw new RuntimeException('Hay clases con horario libre. Asígnales un bloque antes de revertir esta migración.');
        }

        Schema::table('sesiones_horario', function (Blueprint $table): void {
            $table->foreignId('bloque_horario_id')->nullable(false)->change();
            $table->dropColumn(['hora_inicio', 'hora_fin']);
        });
    }
};
