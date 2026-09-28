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
        if (! Schema::hasTable('grados') || ! Schema::hasColumn('grados', 'codigo')) {
            return;
        }

        $tooLong = DB::table('grados')
            ->whereNotNull('codigo')
            ->whereRaw('length(codigo) > 5')
            ->exists();

        if ($tooLong) {
            throw new RuntimeException('Hay códigos de grado de más de 5 caracteres. Corrígelos antes de aplicar esta migración.');
        }

        Schema::table('grados', function (Blueprint $table): void {
            $table->string('codigo', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('grados') || ! Schema::hasColumn('grados', 'codigo')) {
            return;
        }

        Schema::table('grados', function (Blueprint $table): void {
            $table->string('codigo')->nullable()->change();
        });
    }
};
