<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de AUDITORIA de PLATAFORMA (BD CENTRAL) — append-only (RN-LA-001..006, RG-002).
 *
 * Registra acciones sensibles a nivel de plataforma (superadmin sobre colegios,
 * planes, RBAC central, suspensiones, accesos, etc.). Es un log inmutable:
 *   - RN-LA-002: solo se agregan filas, nunca se editan ni se borran. Por eso la
 *     tabla NO tiene `updated_at` ni soft-deletes; solo `created_at`.
 *   - RN-LA-003: se guarda quien (actor), que (accion+recurso), sobre que colegio
 *     (tenant_id), el antes/despues (valor_previo/valor_nuevo) y el contexto de
 *     red (ip, user_agent).
 *
 * `actor_id` se guarda desnormalizado (sin FK) junto con `actor_email`/`actor_rol`
 * para que el registro sobreviva aunque el actor sea eliminado o cambie de rol.
 */
return new class extends Migration
{
    /**
     * La tabla vive en la BD CENTRAL (conexion default en contexto no-tenant).
     */
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Quien ejecuto la accion (desnormalizado; nullable para eventos de sistema).
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_rol')->nullable();

            // Que ocurrio.
            $table->string('accion');                        // CREATE|UPDATE|DELETE|READ|LOGIN|SUSPEND|...
            $table->string('recurso');                       // p.ej. tenant, plan, rbac_role
            $table->string('recurso_id')->nullable();        // id del recurso afectado (string: soporta uuid/int)

            // Sobre que colegio impacta (id del tenant afectado; nullable si es global).
            $table->string('tenant_id')->nullable();

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
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
