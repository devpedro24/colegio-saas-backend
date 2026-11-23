<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const KEYS = ['academico.matriculas.registrar_externas', 'academico.matriculas.aprobar_equivalencias'];

    public function up(): void
    {
        Schema::create('matricula_certificados_externos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('colegio_origen');
            $table->string('grado_origen');
            $table->string('escala_origen');
            $table->date('fecha_ingreso');
            $table->foreignId('periodo_ingreso_id')->constrained('periodos')->restrictOnDelete();
            $table->string('periodo_ingreso');
            $table->char('archivo_token', 24)->unique();
            $table->string('archivo_nombre');
            $table->char('sha256', 64);
            $table->char('huella', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('registrado_por');
            $table->timestampTz('registrado_en');
            $table->unique(['matricula_id', 'sha256']);
            $table->unique(['id', 'matricula_id']);
        });
        Schema::create('matricula_definitivas_externas', function (Blueprint $table) {
            $table->id();
            $table->char('token', 24)->unique();
            $table->unsignedBigInteger('matricula_id');
            $table->unsignedBigInteger('certificado_id');
            $table->foreign(['certificado_id', 'matricula_id'], 'definitiva_certificado_fk')
                ->references(['id', 'matricula_id'])->on('matricula_certificados_externos')->restrictOnDelete();
            $table->string('materia_origen');
            $table->string('periodo_origen');
            $table->date('desde');
            $table->date('hasta');
            $table->string('valor_original');
            $table->foreignId('materia_id')->nullable()->constrained('materias')->restrictOnDelete();
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->restrictOnDelete();
            $table->jsonb('decision')->nullable();
            $table->foreignId('aprobador_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('aprobado_en')->nullable();
            $table->unique(['matricula_id', 'materia_id', 'periodo_id'], 'definitiva_destino_unico');
        });
        foreach (self::KEYS as $key) {
            $permission = Permission::findOrCreate($key, 'web');
            Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_definitivas_externas');
        Schema::dropIfExists('matricula_certificados_externos');
        Permission::whereIn('name', self::KEYS)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
