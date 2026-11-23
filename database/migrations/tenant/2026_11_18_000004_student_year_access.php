<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('anos_lectivos', fn (Blueprint $t) => $t->boolean('acceso_estudiantil')->default(false));
        DB::table('anos_lectivos')->where('estado', '!=', 'planificado')->update(['acceso_estudiantil' => true]);
        Schema::table('ingreso_documentos', fn (Blueprint $t) => $t->boolean('anexo_renovacion')->default(false));
    }

    public function down(): void
    {
        Schema::table('ingreso_documentos', fn (Blueprint $t) => $t->dropColumn('anexo_renovacion'));
        Schema::table('anos_lectivos', fn (Blueprint $t) => $t->dropColumn('acceso_estudiantil'));
    }
};
