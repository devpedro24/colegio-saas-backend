<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Elimina credenciales temporales reversibles de la base central. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'rector_temporary_password')) {
            return;
        }

        // Sobrescribe primero los payloads legacy para que una copia logica
        // tomada durante el despliegue tampoco conserve secretos recuperables.
        DB::table('tenants')
            ->whereNotNull('rector_temporary_password')
            ->update(['rector_temporary_password' => null]);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('rector_temporary_password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'rector_temporary_password')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->text('rector_temporary_password')->nullable()->after('status');
            });
        }
    }
};
