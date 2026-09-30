<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->string('peso_area_texto', 8)->nullable();
        });

        DB::table('materias_curriculares')->whereNotNull('peso_area')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $value = (string) $row->peso_area;
                    if (str_contains($value, '.')) {
                        $value = rtrim(rtrim($value, '0'), '.');
                    }
                    DB::table('materias_curriculares')->where('id', $row->id)
                        ->update(['peso_area_texto' => $value]);
                }
            });

        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->dropColumn('peso_area');
        });
        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->renameColumn('peso_area_texto', 'peso_area');
        });
    }

    public function down(): void
    {
        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->decimal('peso_area_decimal', 7, 4)->nullable();
        });
        DB::table('materias_curriculares')->whereNotNull('peso_area')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('materias_curriculares')->where('id', $row->id)
                        ->update(['peso_area_decimal' => $row->peso_area]);
                }
            });
        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->dropColumn('peso_area');
        });
        Schema::table('materias_curriculares', function (Blueprint $table) {
            $table->renameColumn('peso_area_decimal', 'peso_area');
        });
    }
};
