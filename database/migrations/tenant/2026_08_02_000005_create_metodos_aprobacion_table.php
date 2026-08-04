<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metodo de aprobacion del COLEGIO (BD del tenant) — bloque 5 de configuracion.
 *
 * Define como se consolida la aprobacion: por promedio simple, ponderado o
 * sumatoria dividida; la nota minima para aprobar; y el ambito al que aplica la
 * decision (materia, area o general). Se versiona por ano lectivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_aprobacion', function (Blueprint $table) {
            $table->id();

            // Version por ano lectivo (la tabla la crea AGENT-A).
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos');

            $table->string('calculo_nota')->default('promedio_simple'); // promedio_simple | ponderado | sumatoria
            $table->decimal('nota_minima', 5, 2)->default(3);
            $table->string('ambito')->default('materia');          // materia | area | promedio_general

            $table->timestamps();

            $table->index('ano_lectivo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_aprobacion');
    }
};
