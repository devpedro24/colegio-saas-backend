<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materias', fn (Blueprint $table) => $table->index(
            ['ano_lectivo_id', 'nombre', 'id'], 'materias_year_name_page_idx'));
        Schema::table('grupos', fn (Blueprint $table) => $table->index(
            ['ano_lectivo_id', 'grado_id', 'nombre', 'id'], 'grupos_year_grade_page_idx'));
        Schema::table('asignaciones_docentes', function (Blueprint $table): void {
            $table->index(['ano_lectivo_id', 'deleted_at', 'id'], 'asignaciones_year_page_idx');
            $table->index(['docente_id', 'deleted_at', 'id'], 'asignaciones_teacher_page_idx');
        });
        Schema::table('matriculas', function (Blueprint $table): void {
            $table->index(['ano_lectivo_id', 'id'], 'matriculas_year_page_idx');
            $table->index(['grupo_id', 'estado', 'id'], 'matriculas_group_state_page_idx');
        });
        Schema::table('materias_curriculares', fn (Blueprint $table) => $table->index(
            ['ano_lectivo_id', 'area_id', 'grado_id'], 'curriculo_year_area_grade_idx'));
    }

    public function down(): void
    {
        Schema::table('materias_curriculares', fn (Blueprint $table) => $table->dropIndex('curriculo_year_area_grade_idx'));
        Schema::table('matriculas', function (Blueprint $table): void {
            $table->dropIndex('matriculas_group_state_page_idx');
            $table->dropIndex('matriculas_year_page_idx');
        });
        Schema::table('asignaciones_docentes', function (Blueprint $table): void {
            $table->dropIndex('asignaciones_teacher_page_idx');
            $table->dropIndex('asignaciones_year_page_idx');
        });
        Schema::table('grupos', fn (Blueprint $table) => $table->dropIndex('grupos_year_grade_page_idx'));
        Schema::table('materias', fn (Blueprint $table) => $table->dropIndex('materias_year_name_page_idx'));
    }
};
