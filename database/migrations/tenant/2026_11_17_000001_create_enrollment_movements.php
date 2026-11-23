<?php

declare(strict_types=1);

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
        Schema::create('matricula_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('grupo_origen_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('grupo_destino_id')->constrained('grupos')->restrictOnDelete();
            $table->date('fecha_efectiva');
            $table->text('origen');
            $table->text('destino');
            $table->text('motivo');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('responsable');
            $table->timestampTz('registrado_en');
            $table->unique(['matricula_id', 'version']);
            $table->index(['matricula_id', 'fecha_efectiva']);
            $table->index(['grupo_origen_id', 'fecha_efectiva']);
            $table->index(['grupo_destino_id', 'fecha_efectiva']);
        });
        $permission = Permission::findOrCreate('academico.matriculas.corregir', 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_movimientos');
        Permission::where('name', 'academico.matriculas.corregir')->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
