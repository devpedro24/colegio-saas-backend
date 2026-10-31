<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicadores_vinculos', function (Blueprint $table): void {
            $table->foreignId('grupo_id')->nullable()->after('grado_id')
                ->constrained('grupos')->restrictOnDelete();
            $table->index(['grupo_id', 'materia_id', 'estado'], 'indicadores_grupo_contexto_idx');
        });
    }

    public function down(): void
    {
        Schema::table('indicadores_vinculos', function (Blueprint $table): void {
            $table->dropIndex('indicadores_grupo_contexto_idx');
            $table->dropConstrainedForeignId('grupo_id');
        });
    }
};
