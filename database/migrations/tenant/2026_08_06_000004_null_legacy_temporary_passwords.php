<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina secretos temporales reversibles creados por versiones anteriores.
 *
 * Las nuevas contrasenas temporales solo se entregan una vez en la respuesta
 * autorizada de creacion/reset y nunca vuelven a persistirse recuperables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'temporary_password')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('temporary_password')
            ->update(['temporary_password' => null]);
    }

    public function down(): void
    {
        // Irreversible por seguridad: no es posible ni deseable reconstruir
        // secretos que ya fueron eliminados.
    }
};
