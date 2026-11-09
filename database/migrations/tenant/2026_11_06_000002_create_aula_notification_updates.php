<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aula_notification_publications', function (Blueprint $table) {
            $table->foreignId('recurso_id')->primary()->constrained('aula_recursos')->cascadeOnDelete();
            $table->boolean('visible')->default(false);
            $table->unsignedInteger('revision')->default(0);
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('user_notification_updates', function (Blueprint $table) {
            $table->string('url_token', 24)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurso_id')->constrained('aula_recursos')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('deduplication_key', 100);
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'recurso_id', 'deduplication_key'], 'notification_updates_once');
            $table->index(['user_id', 'created_at', 'url_token'], 'notification_updates_owner_date');
        });

        // Establish the existing visibility baseline without announcing historical content.
        DB::table('aula_recursos as r')->join('aula_secciones as s', 's.id', '=', 'r.seccion_id')
            ->select('r.id', 'r.autor_id', 'r.deleted_at', 'r.visible_estudiantes', 'r.estado', 'r.disponible_desde',
                's.deleted_at as section_deleted', 's.visible_estudiantes as section_visible')
            ->orderBy('r.id')->chunk(200, function ($rows) {
                $states = [];
                foreach ($rows as $row) {
                    $visible = ! $row->deleted_at && ! $row->section_deleted && $row->visible_estudiantes && $row->section_visible
                        && in_array($row->estado, ['publicado', 'programado', 'cerrado'], true)
                        && ($row->estado !== 'programado' || $row->disponible_desde)
                        && (! $row->disponible_desde || now('UTC')->gte($row->disponible_desde));
                    $states[] = [
                        'recurso_id' => $row->id, 'actor_id' => $row->autor_id, 'visible' => (bool) $visible, 'revision' => 0,
                    ];
                }
                DB::table('aula_notification_publications')->insert($states);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_updates');
        Schema::dropIfExists('aula_notification_publications');
    }
};
