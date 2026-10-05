<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escala_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escala_id')->constrained('escalas_valorativas')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->unsignedTinyInteger('orden');
            $table->decimal('valor_equivalente', 5, 2);
            $table->string('emoji', 16)->nullable();
            $table->string('imagen_path', 255)->nullable();
            $table->boolean('aprueba')->default(false);
            $table->timestamps();
            $table->index(['escala_id', 'orden']);
        });
        Schema::table('calificaciones', function (Blueprint $table) {
            $table->foreignId('escala_opcion_id')->nullable()->constrained('escala_opciones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('calificaciones') && Schema::hasColumn('calificaciones', 'escala_opcion_id')) {
            Schema::table('calificaciones', fn (Blueprint $table) => $table->dropConstrainedForeignId('escala_opcion_id'));
        }
        Schema::dropIfExists('escala_opciones');
    }
};
