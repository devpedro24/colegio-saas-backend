<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const KEYS = ['academico.matriculas.registrar_valoraciones', 'academico.matriculas.aprobar_valoraciones'];

    public function up(): void
    {
        Schema::create('matricula_valoraciones_ingreso', function (Blueprint $table) {
            $table->id();
            $table->char('token', 24)->unique();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->date('fecha_ingreso');
            $table->foreignId('registrador_id')->constrained('users')->restrictOnDelete();
            $table->string('registrado_por');
            $table->timestampTz('registrado_en');
            $table->text('motivo');
            $table->jsonb('propuesta');
            $table->char('huella', 64);
            $table->char('archivo_token', 24)->unique();
            $table->string('archivo_nombre');
            $table->jsonb('decision')->nullable();
            $table->foreignId('aprobador_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('aprobado_en')->nullable();
            $table->unique(['matricula_id', 'materia_id', 'periodo_id'], 'valoracion_ingreso_destino_unico');
        });
        foreach (self::KEYS as $key) {
            $permission = Permission::findOrCreate($key, 'web');
            Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_valoraciones_ingreso');
        Permission::whereIn('name', self::KEYS)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
