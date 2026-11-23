<?php

use App\Services\Ingreso\ClosedGradeAuthorizations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // An individual final is an institutional grade with no implied transfer or arrival date.
        Schema::table('matricula_valoraciones_ingreso', fn (Blueprint $t) => $t->date('fecha_ingreso')->nullable()->change());
        Schema::create(ClosedGradeAuthorizations::TABLE, function (Blueprint $t) {
            $t->id();
            $t->char('token', 24)->unique();
            $t->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $t->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $t->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $t->foreignId('autorizador_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->string('autorizado_por');
            $t->string('responsable');
            $t->timestampTz('autorizado_en');
            $t->text('motivo');
            $t->char('archivo_token', 24)->unique();
            $t->string('archivo_nombre');
            $t->char('version_escala', 64);
            $t->char('huella', 64);
            $t->jsonb('anterior');
            $t->foreignId('valoracion_id')->nullable()->unique()->constrained('matricula_valoraciones_ingreso')->restrictOnDelete();
            $t->timestampTz('revocado_en')->nullable();
            $t->index(['matricula_id','materia_id','periodo_id'], 'autorizacion_cierre_destino');
        });
        $permission = Permission::findOrCreate(ClosedGradeAuthorizations::AUTHORIZE, 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table(ClosedGradeAuthorizations::TABLE)->exists()) {
            throw new RuntimeException('Existen autorizaciones individuales. Conserva sus soportes e historial antes de revertir.');
        }
        Schema::dropIfExists(ClosedGradeAuthorizations::TABLE);
        Schema::table('matricula_valoraciones_ingreso', fn (Blueprint $t) => $t->date('fecha_ingreso')->nullable(false)->change());
        Permission::where('name', ClosedGradeAuthorizations::AUTHORIZE)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
