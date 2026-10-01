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
        Schema::table('periodos', function (Blueprint $table) {
            $table->json('configuracion_notas')->nullable();
            $table->unsignedInteger('version_notas')->default(0);
        });
        Schema::create('preinformes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('orden');
            $table->decimal('peso', 7, 4)->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
            $table->index(['periodo_id', 'orden']);
        });
        Schema::table('componentes_evaluacion', function (Blueprint $table) {
            $table->foreignId('preinforme_id')->nullable()->constrained('preinformes')->restrictOnDelete();
            $table->boolean('es_directo')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->unique(['asignacion_id', 'preinforme_id'], 'planilla_preinforme_unique');
        });
        Schema::table('actividades_evaluacion', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        // Unconstrained NUMERIC retains exact values without fixed-scale padding.
        // Existing values are mathematically unchanged; no float conversion.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE calificaciones ALTER COLUMN valor TYPE numeric USING trim_scale(valor::numeric)');
            DB::statement('ALTER TABLE calificaciones ADD CONSTRAINT calificaciones_valor_precision CHECK (valor IS NULL OR (valor BETWEEN -99999999 AND 99999999 AND scale(valor) <= 8))');
        }
    }

    public function down(): void
    {
        // Restoring an older schema must not delete assessment records silently.
        if (DB::table('preinformes')->exists()) {
            throw new RuntimeException('Hay preinformes registrados; conserva sus datos antes de revertir.');
        }
        Schema::table('actividades_evaluacion', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('componentes_evaluacion', function (Blueprint $table) {
            $table->dropUnique('planilla_preinforme_unique');
            $table->dropConstrainedForeignId('preinforme_id');
            $table->dropColumn(['es_directo', 'version']);
        });
        Schema::dropIfExists('preinformes');
        Schema::table('periodos', fn (Blueprint $table) => $table->dropColumn(['configuracion_notas', 'version_notas']));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE calificaciones DROP CONSTRAINT calificaciones_valor_precision');
        }
    }
};
