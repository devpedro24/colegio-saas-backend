<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->string('correo_estado', 12)->nullable();
            $table->timestampTz('correo_disponible_en')->nullable();
            $table->unsignedSmallInteger('correo_intentos')->default(0);
            $table->index(['correo_estado', 'correo_disponible_en'], 'aula_mail_due');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->dropIndex('aula_mail_due');
            $table->dropColumn(['correo_estado', 'correo_disponible_en', 'correo_intentos']);
        });
    }
};
