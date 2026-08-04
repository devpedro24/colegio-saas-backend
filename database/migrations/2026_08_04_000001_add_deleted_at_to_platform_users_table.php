<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD CENTRAL: soft-delete en usuarios de plataforma (RG-004, RN-BR-005).
 *
 * El borrado de un usuario de plataforma es LOGICO: la fila sobrevive con
 * `deleted_at` hasta la ventana de retencion, cuando una purga fisica la
 * elimina. Los registros de auditoria siguen apuntando al actor por
 * desnormalizacion (actor_email), por lo que la evidencia no se pierde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
