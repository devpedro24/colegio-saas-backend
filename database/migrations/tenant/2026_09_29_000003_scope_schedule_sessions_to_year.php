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
        // Comprobar antes de cambiar el esquema: también cuentan clases eliminadas
        // lógicamente, porque sus referencias históricas deben conservarse.
        if (DB::table('sesiones_horario as s')
            ->leftJoin('grupos as g', 'g.id', '=', 's.grupo_id')
            ->whereNull('g.ano_lectivo_id')->exists()) {
            throw new RuntimeException('Hay clases sin grupo o año lectivo válido. Corrige sus referencias antes de migrar.');
        }

        Schema::table('sesiones_horario', function (Blueprint $table): void {
            $table->foreignId('ano_lectivo_id')->nullable()->constrained('anos_lectivos')->restrictOnDelete();
        });
        DB::table('sesiones_horario as s')
            ->join('grupos as g', 'g.id', '=', 's.grupo_id')
            ->select('s.id', 'g.ano_lectivo_id')
            ->orderBy('s.id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('sesiones_horario')->where('id', $row->id)
                        ->update(['ano_lectivo_id' => $row->ano_lectivo_id]);
                }
            }, 's.id', 'id');
        Schema::table('sesiones_horario', function (Blueprint $table): void {
            $table->foreignId('ano_lectivo_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_horario', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ano_lectivo_id');
        });
    }
};
