<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->string('estado', 20)->default('publicado')->index();
            $table->timestamp('publicado_en')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('enviar_correo')->default(false);
            $table->text('correo_contenido')->nullable();
            $table->string('categoria_anterior', 30)->nullable();
        });
        DB::table('eventos')->update(['publicado_en' => DB::raw('created_at')]);
        // Preserve historical records; an old agenda item is not a classroom task.
        DB::table('eventos')->where('categoria', 'tarea')->update(['categoria_anterior' => 'tarea', 'categoria' => 'actividad']);
        DB::table('configuracion_eventos')->update(['docentes_cualquier_grupo' => false]);
        Schema::table('aula_recursos', function (Blueprint $table) {
            $table->timestamp('publicado_en')->nullable()->index();
            $table->boolean('publicacion_estimada')->default(false);
        });
        DB::table('aula_recursos')->where('visible_estudiantes', true)
            ->whereIn('estado', ['publicado', 'programado', 'cerrado'])
            ->whereIn('seccion_id', DB::table('aula_secciones')->where('visible_estudiantes', true)->whereNull('deleted_at')->select('id'))
            ->update(['publicado_en' => DB::raw('CASE WHEN disponible_desde > created_at THEN disponible_desde ELSE created_at END'), 'publicacion_estimada' => true]);
        Schema::create('evento_correos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->restrictOnDelete();
            $table->foreignId('estudiante_id')->constrained('users')->restrictOnDelete();
            $table->text('email');
            $table->string('idioma', 5)->default('es');
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedInteger('intentos')->default(0);
            $table->timestamp('disponible_en')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->string('ultimo_error', 40)->nullable();
            $table->timestamps();
            $table->unique(['evento_id', 'estudiante_id']);
            $table->index(['estado', 'disponible_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_correos');
        DB::table('eventos')->whereNotNull('categoria_anterior')->update(['categoria' => DB::raw('categoria_anterior')]);
        Schema::table('eventos', fn (Blueprint $table) => $table->dropColumn(['estado', 'publicado_en', 'version', 'enviar_correo', 'correo_contenido', 'categoria_anterior']));
        Schema::table('aula_recursos', fn (Blueprint $table) => $table->dropColumn(['publicado_en', 'publicacion_estimada']));
    }
};
