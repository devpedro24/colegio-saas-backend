<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de AUDITORIA del COLEGIO (BD DEL TENANT) — append-only (RN-LA-001..006, RG-002).
 *
 * Registra acciones sensibles dentro de un colegio (notas, convivencia, matriculas,
 * gestion de usuarios del tenant, etc.). Es un log inmutable:
 *   - RN-LA-002: solo se agregan filas, nunca se editan ni se borran. Por eso la
 *     tabla NO tiene `updated_at` ni soft-deletes; solo `created_at`.
 *
 * No lleva `tenant_id`: el colegio es IMPLICITO por la base de datos aislada del
 * tenant (RN-AI-001). `actor_id` referencia a `users` del propio tenant, pero se
 * guarda desnormalizado con `actor_email`/`actor_rol` (sin FK) para que el registro
 * sobreviva aunque el usuario sea eliminado o cambie de rol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Quien ejecuto la accion (users del tenant; desnormalizado; nullable en eventos de sistema).
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_rol')->nullable();

            // Que ocurrio.
            $table->string('accion');                        // CREATE|UPDATE|DELETE|READ|LOGIN|SUSPEND|...
            $table->string('recurso');                       // p.ej. estudiante, nota, incidencia
            $table->string('recurso_id')->nullable();        // id del recurso afectado (string: soporta uuid/int)

            // Antes / despues del cambio (RN-LA-003).
            $table->json('valor_previo')->nullable();
            $table->json('valor_nuevo')->nullable();

            // Contexto.
            $table->text('motivo')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();

            // Append-only: unico timestamp, fijado por la BD al insertar.
            $table->timestamp('created_at')->useCurrent();

            $table->index('recurso');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
