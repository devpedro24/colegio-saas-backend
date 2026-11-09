<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE user_notification_updates DROP CONSTRAINT notification_updates_source');
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->foreignId('evento_id')->nullable()->constrained('eventos')->cascadeOnDelete();
            $table->unique(['user_id', 'evento_id', 'deduplication_key'], 'calendar_updates_once');
        });
        DB::statement('ALTER TABLE user_notification_updates ADD CONSTRAINT notification_updates_source CHECK ((recurso_id IS NOT NULL AND asistencia_marca_id IS NULL AND asistencia_solicitud_id IS NULL AND evento_id IS NULL) OR (recurso_id IS NULL AND asistencia_marca_id IS NOT NULL AND evento_id IS NULL) OR (recurso_id IS NULL AND asistencia_marca_id IS NULL AND asistencia_solicitud_id IS NULL AND evento_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::table('user_notification_updates')->whereNotNull('evento_id')->delete();
        DB::statement('ALTER TABLE user_notification_updates DROP CONSTRAINT notification_updates_source');
        Schema::table('user_notification_updates', function (Blueprint $table) {
            $table->dropUnique('calendar_updates_once');
            $table->dropConstrainedForeignId('evento_id');
        });
        DB::statement('ALTER TABLE user_notification_updates ADD CONSTRAINT notification_updates_source CHECK ((recurso_id IS NOT NULL AND asistencia_marca_id IS NULL AND asistencia_solicitud_id IS NULL) OR (recurso_id IS NULL AND asistencia_marca_id IS NOT NULL))');
    }
};
