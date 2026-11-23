<?php

use App\Services\Ingreso\EntryAssessments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matricula_valoraciones_ingreso', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0);
        });
        Schema::create('matricula_rectificaciones_valoraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_id')->constrained('matricula_valoraciones_ingreso')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unique(['resultado_id', 'version'], 'valoracion_version_unica');
            $table->jsonb('decision_anterior');
            $table->foreignId('aprobador_anterior_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('aprobacion_anterior');
            $table->jsonb('decision_nueva');
            $table->text('motivo');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('registrado_en');
            $table->char('archivo_token', 24)->unique();
            $table->string('archivo_nombre');
            $table->char('huella', 64);
        });
        $permission = Permission::findOrCreate(EntryAssessments::RECTIFY, 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_rectificaciones_valoraciones');
        Schema::table('matricula_valoraciones_ingreso', fn (Blueprint $table) => $table->dropColumn('version'));
        Permission::where('name', EntryAssessments::RECTIFY)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
