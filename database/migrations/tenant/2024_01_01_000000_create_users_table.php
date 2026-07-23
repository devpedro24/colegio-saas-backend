<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BD DEL TENANT (colegio): usuarios del colegio.
     *
     * Rector, coordinadores, secretaria, docentes y estudiantes. El acudiente
     * NO es un usuario (RN-TU-410): opera la cuenta del estudiante y sus datos
     * viven como contacto dentro de la ficha del estudiante (fase posterior).
     *
     * El correo es unico DENTRO del tenant (RN-CV-003); como cada colegio tiene
     * su propia BD, la unicidad global se garantiza por aislamiento.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->nullable();                     // placeholder hasta el motor RBAC (spatie)
            $table->string('status')->default('active');            // Pendiente|Activo|Inactivo|Suspendido (RN-CV-001)
            $table->boolean('must_change_password')->default(true); // credencial temporal en alta (RN-AU-360)
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Sesiones dentro del contexto del tenant (el middleware `web` las usa).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
