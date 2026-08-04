<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos institucionales del COLEGIO (BD del tenant) — bloque 1 de configuracion.
 *
 * Singleton: una unica fila por colegio (RN-CC-*). No se versiona por ano
 * lectivo, a diferencia del resto de bloques de configuracion; describe la
 * identidad del establecimiento, que es estable en el tiempo.
 *
 * `name` y `nit` NO viven aqui: son columnas del tenant en la BD CENTRAL. Esta
 * tabla guarda el resto de la ficha institucional (resolucion MEN, contacto,
 * logos e identidad visual). `colores` es un JSON con la paleta de marca
 * (p.ej. {"primario":"#123456","secundario":"#abcdef"}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_institucionales', function (Blueprint $table) {
            $table->id();

            // Identidad basica (mostrada en documentos). El colegio la edita aqui.
            $table->string('nombre')->nullable();
            $table->string('nit')->nullable();

            // Ficha legal / de contacto.
            $table->string('resolucion_men')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('correo')->nullable();

            // Identidad visual (rutas de almacenamiento de los archivos).
            $table->string('logo_principal')->nullable();
            $table->string('logo_documentos')->nullable();
            $table->string('isotipo')->nullable();

            // Paleta de marca: {"primario":"#...","secundario":"#..."}.
            $table->json('colores')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_institucionales');
    }
};
