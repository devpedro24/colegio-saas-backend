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
        Schema::table('matricula_movimientos', function (Blueprint $table) {
            $table->unsignedInteger('rectifica_version')->nullable();
            $table->foreign(['matricula_id', 'rectifica_version'], 'movimiento_rectificado_fk')
                ->references(['matricula_id', 'version'])->on('matricula_movimientos')->restrictOnDelete();
            $table->unique(['matricula_id', 'rectifica_version'], 'movimiento_rectificado_unico');
        });
        Schema::create('matricula_movimiento_soportes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->constrained('matricula_movimientos')->restrictOnDelete();
            $table->char('archivo_token', 24)->unique();
            $table->char('huella', 64);
            $table->string('nombre');
            $table->boolean('inicial');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('responsable');
            $table->text('motivo');
            $table->timestampTz('registrado_en');
            $table->unique(['movimiento_id', 'huella']);
        });
        $permission = Permission::findOrCreate('academico.matriculas.rectificar', 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_movimiento_soportes');
        Schema::table('matricula_movimientos', function (Blueprint $table) {
            $table->dropForeign('movimiento_rectificado_fk');
            $table->dropUnique('movimiento_rectificado_unico');
            $table->dropColumn('rectifica_version');
        });
        Permission::where('name', 'academico.matriculas.rectificar')->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
