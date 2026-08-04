<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de SUPLANTACION del superadministrador (BD CENTRAL).
 *
 * Cuando el superadmin entra a administrar un colegio (RN-RT-402 / RN-LA-002),
 * se registra aqui la sesion: quien suplanta (superadmin), a que colegio
 * (tenant_id) y su ventana de validez (started_at / expires_at). Al salir se
 * marca `ended_at`. Es la fuente de verdad para resolver `impersonated_by` al
 * auditar acciones dentro del colegio.
 *
 * `superadmin_id`/`superadmin_email` se guardan desnormalizados (sin FK) para
 * que el registro sobreviva aunque el superadmin cambie o se elimine.
 */
return new class extends Migration
{
    /**
     * La tabla vive en la BD CENTRAL (conexion default en contexto no-tenant).
     */
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Quien suplanta (superadmin de plataforma; desnormalizado).
            $table->unsignedBigInteger('superadmin_id');
            $table->string('superadmin_email');

            // Colegio suplantado (uuid del tenant).
            $table->uuid('tenant_id')->index();

            // Ventana de validez de la sesion de suplantacion.
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
