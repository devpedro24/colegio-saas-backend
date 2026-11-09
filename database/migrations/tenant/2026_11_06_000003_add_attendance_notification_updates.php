<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('recurso_id')->nullable()->change();
            $table->foreignId('asistencia_marca_id')->nullable()->constrained('asistencia_marcas')->cascadeOnDelete();
            $table->foreignId('asistencia_solicitud_id')->nullable()->constrained('asistencia_solicitudes')->cascadeOnDelete();
            $table->unique(['user_id', 'asistencia_marca_id', 'deduplication_key'], 'attendance_updates_once');
        });
        DB::statement('ALTER TABLE user_notification_updates ADD CONSTRAINT notification_updates_source CHECK ((recurso_id IS NOT NULL AND asistencia_marca_id IS NULL AND asistencia_solicitud_id IS NULL) OR (recurso_id IS NULL AND asistencia_marca_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::table('user_notification_updates')->whereNotNull('asistencia_marca_id')->delete();
        DB::statement('ALTER TABLE user_notification_updates DROP CONSTRAINT notification_updates_source');
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->dropUnique('attendance_updates_once');
            $table->dropConstrainedForeignId('asistencia_solicitud_id');
            $table->dropConstrainedForeignId('asistencia_marca_id');
            $table->unsignedBigInteger('recurso_id')->nullable(false)->change();
        });
    }
};
