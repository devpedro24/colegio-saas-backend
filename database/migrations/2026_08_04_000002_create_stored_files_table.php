<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD CENTRAL: registros de ARCHIVOS SUBIDOS por los colegios (RN-AC-001..006).
 *
 * Metadato del pipeline unico de archivos (D-STORAGE). Vive en la BD central
 * porque es infraestructura de PLATAFORMA (cuotas por plan, URLs firmadas y
 * descarga con firma se resuelven fuera del contexto del colegio), mientras
 * los BYTES del archivo viven en el storage aislado de cada tenant
 * (storage/app/tenants/<tenant_id>/...).
 *
 * `tenant_id` desnormaliza el colegio dueno; `checksum` (sha256) permite
 * detectar archivos duplicados. Soft-delete (RG-004): la fila queda hasta la
 * ventana de retencion, cuando la purga fisica borra tambien el objeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('tenant_id')->index();        // colegio dueno
            $table->string('disk')->default('tenant');   // disco de destino
            $table->string('path');                      // ruta relativa al disco
            $table->string('mime', 120);                 // MIME validado por whitelist
            $table->unsignedBigInteger('size');          // bytes (para cuota)
            $table->char('checksum', 64);                // sha256 del contenido
            $table->string('original_name', 255);        // nombre del cliente (solo referencia)
            $table->string('uploaded_by_email')->nullable(); // quien subio (desnormalizado)

            $table->timestamps();
            $table->softDeletes();

            // Un mismo contenido no debe ocupar doble cuota del mismo colegio.
            $table->unique(['tenant_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
