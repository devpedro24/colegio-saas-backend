<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matricula_certificados_pendientes', function (Blueprint $t) {
            $t->unsignedBigInteger('certificado_revisado_id')->nullable();
            $t->foreign(['certificado_revisado_id', 'matricula_id'], 'revision_certificado_matricula_fk')
                ->references(['id', 'matricula_id'])->on('matricula_certificados_externos')->restrictOnDelete();
            $t->text('motivo_sin_equivalencia')->nullable();
            $t->foreignId('revisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('revisado_en')->nullable();
        });
        DB::statement('ALTER TABLE matricula_certificados_pendientes ADD CONSTRAINT revision_sin_equivalencia_consistente CHECK (
            (certificado_revisado_id IS NULL AND motivo_sin_equivalencia IS NULL AND revisor_id IS NULL AND revisado_en IS NULL)
            OR (certificado_revisado_id IS NOT NULL AND resultado_id IS NULL AND motivo_sin_equivalencia IS NOT NULL
                AND revisor_id IS NOT NULL AND revisado_en IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE matricula_certificados_pendientes DROP CONSTRAINT revision_sin_equivalencia_consistente');
        Schema::table('matricula_certificados_pendientes', function (Blueprint $t) {
            $t->dropForeign('revision_certificado_matricula_fk');
            $t->dropConstrainedForeignId('revisor_id');
            $t->dropColumn(['certificado_revisado_id', 'motivo_sin_equivalencia', 'revisado_en']);
        });
    }
};
