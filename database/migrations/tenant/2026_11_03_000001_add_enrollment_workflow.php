<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_campanas', function (Blueprint $table) {
            $table->timestamp('apertura_en')->nullable();
            $table->timestamp('cierre_en')->nullable();
            $table->timestamp('correcciones_hasta')->nullable();
        });
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->boolean('pin_manual')->default(false);
            $table->timestamp('pin_activado_en')->nullable();
            $table->string('identidad_lookup', 64)->nullable();
            $table->json('requisitos_snapshot')->nullable();
            $table->json('correcciones')->nullable();
            $table->timestamp('validada_en')->nullable();
            $table->unique(['campana_id', 'identidad_lookup']);
        });
        Schema::table('ingreso_documentos', function (Blueprint $table) {
            $table->uuid('carga_token')->nullable();
            $table->unique(['solicitud_id', 'carga_token']);
        });
    }

    public function down(): void
    {
        Schema::table('ingreso_documentos', function (Blueprint $table) {
            $table->dropUnique(['solicitud_id', 'carga_token']);
            $table->dropColumn('carga_token');
        });
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->dropUnique(['campana_id', 'identidad_lookup']);
            $table->dropColumn(['pin_manual', 'pin_activado_en', 'identidad_lookup', 'requisitos_snapshot', 'correcciones', 'validada_en']);
        });
        Schema::table('ingreso_campanas', fn (Blueprint $table) => $table->dropColumn(['apertura_en', 'cierre_en', 'correcciones_hasta']));
    }
};
