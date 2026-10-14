<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->index(['fecha', 'estado', 'categoria'], 'eventos_fecha_estado_categoria_idx');
            $table->index(['created_at', 'id'], 'eventos_creacion_cursor_idx');
        });
        Schema::table('evento_grupo', function (Blueprint $table) {
            $table->index(['grupo_id', 'evento_id'], 'evento_grupo_grupo_evento_idx');
        });
    }

    public function down(): void
    {
        Schema::table('evento_grupo', fn (Blueprint $table) => $table->dropIndex('evento_grupo_grupo_evento_idx'));
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropIndex('eventos_creacion_cursor_idx');
            $table->dropIndex('eventos_fecha_estado_categoria_idx');
        });
    }
};
