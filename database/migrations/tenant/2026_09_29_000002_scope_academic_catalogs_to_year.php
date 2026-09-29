<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'jornadas', 'niveles', 'grados', 'bloques_horarios',
        'espacios_fisicos', 'areas', 'materias',
    ];

    public function up(): void
    {
        $yearId = DB::table('anos_lectivos')->where('estado', 'en_curso')->value('id')
            ?? DB::table('anos_lectivos')->orderBy('id')->value('id');
        if ($yearId !== null) {
            $otherYears = DB::table('anos_lectivos')->where('id', '!=', $yearId)->pluck('id');
            if ($otherYears->isNotEmpty() && (
                DB::table('grupos')->whereIn('ano_lectivo_id', $otherYears)->exists()
                || DB::table('materias_curriculares')->whereIn('ano_lectivo_id', $otherYears)->exists()
                || DB::table('anos_lectivos')->whereIn('id', $otherYears)->whereNotNull('siee')->exists()
            )) {
                throw new RuntimeException('Hay varios años con datos académicos que comparten catálogos antiguos. Requieren una migración histórica específica antes de versionarlos; no se modificó ningún dato.');
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('ano_lectivo_id')->nullable()->constrained('anos_lectivos')->restrictOnDelete();
            });
        }

        // Los catálogos antiguos eran compartidos. Se preservan en el año en curso
        // (o el primero disponible), sin borrar ni recrear identificadores usados
        // por grupos, horarios, notas o eventos existentes.
        if ($yearId !== null) {
            foreach (self::TABLES as $table) {
                DB::table($table)->whereNull('ano_lectivo_id')->update(['ano_lectivo_id' => $yearId]);
            }
        }

        Schema::table('jornadas', function (Blueprint $table): void {
            $table->dropUnique(['sede_id', 'nombre']);
            $table->unique(['ano_lectivo_id', 'sede_id', 'nombre']);
        });
        Schema::table('niveles', function (Blueprint $table): void {
            $table->dropUnique(['nivel_educativo']);
            $table->unique(['ano_lectivo_id', 'nivel_educativo']);
        });
        Schema::table('espacios_fisicos', function (Blueprint $table): void {
            $table->dropUnique(['sede_id', 'nombre']);
            $table->unique(['ano_lectivo_id', 'sede_id', 'nombre']);
        });
        Schema::table('areas', function (Blueprint $table): void {
            $table->dropUnique(['nombre']);
            $table->unique(['ano_lectivo_id', 'nombre']);
        });
        Schema::table('materias', function (Blueprint $table): void {
            $table->dropUnique(['codigo']);
            $table->unique(['ano_lectivo_id', 'codigo']);
        });
    }

    public function down(): void
    {
        $years = DB::table('anos_lectivos')->count();
        if ($years > 1) {
            throw new RuntimeException('Hay catálogos de varios años. No se puede revertir sin perder su separación histórica.');
        }
        Schema::table('materias', function (Blueprint $table): void {
            $table->dropUnique(['ano_lectivo_id', 'codigo']);
            $table->unique('codigo');
        });
        Schema::table('areas', function (Blueprint $table): void {
            $table->dropUnique(['ano_lectivo_id', 'nombre']);
            $table->unique('nombre');
        });
        Schema::table('espacios_fisicos', function (Blueprint $table): void {
            $table->dropUnique(['ano_lectivo_id', 'sede_id', 'nombre']);
            $table->unique(['sede_id', 'nombre']);
        });
        Schema::table('niveles', function (Blueprint $table): void {
            $table->dropUnique(['ano_lectivo_id', 'nivel_educativo']);
            $table->unique('nivel_educativo');
        });
        Schema::table('jornadas', function (Blueprint $table): void {
            $table->dropUnique(['ano_lectivo_id', 'sede_id', 'nombre']);
            $table->unique(['sede_id', 'nombre']);
        });
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('ano_lectivo_id');
            });
        }
    }
};
