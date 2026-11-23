<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boletin_emisiones', function (Blueprint $table) {
            $table->unsignedBigInteger('rectifica_id')->nullable();
            $table->unique(['id', 'matricula_id', 'contexto'], 'emision_contexto_identidad');
            $table->foreign(['rectifica_id', 'matricula_id', 'contexto'], 'emision_rectificada_fk')
                ->references(['id', 'matricula_id', 'contexto'])->on('boletin_emisiones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boletin_emisiones', function (Blueprint $table) {
            $table->dropForeign('emision_rectificada_fk');
            $table->dropUnique('emision_contexto_identidad');
            $table->dropColumn('rectifica_id');
        });
    }
};
