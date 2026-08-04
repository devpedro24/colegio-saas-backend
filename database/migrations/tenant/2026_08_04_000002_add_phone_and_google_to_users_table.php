<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de perfil del usuario del COLEGIO (tenant): telefono de contacto y la
 * cuenta de Google vinculada desde los ajustes de cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('google_id', 64)->nullable()->after('phone')->unique();
            $table->string('google_email', 190)->nullable()->after('google_id');
            $table->timestamp('google_linked_at')->nullable()->after('google_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['phone', 'google_id', 'google_email', 'google_linked_at']);
        });
    }
};
