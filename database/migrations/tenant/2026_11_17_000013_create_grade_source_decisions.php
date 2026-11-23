<?php

use App\Services\Ingreso\CertifiedExternalGrades;
use App\Services\Ingreso\GradeSourceDecisions;
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
        Schema::table(CertifiedExternalGrades::RESULTS, function (Blueprint $table) {
            $table->jsonb('definitiva_planilla')->nullable();
        });
        Schema::create(GradeSourceDecisions::TABLE, function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_id')->constrained(CertifiedExternalGrades::RESULTS)->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unique(['resultado_id', 'version'], 'eleccion_definitiva_version');
            $table->string('fuente', 16);
            $table->char('huella', 64);
            $table->jsonb('notas');
            $table->text('motivo');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('responsable');
            $table->timestampTz('registrado_en');
        });
        DB::table(CertifiedExternalGrades::RESULTS)->whereNotNull('aprobado_en')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                GradeSourceDecisions::capture($row, historical: true);
            }
        });
        $permission = Permission::findOrCreate(GradeSourceDecisions::CHOOSE, 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists(GradeSourceDecisions::TABLE);
        Schema::table(CertifiedExternalGrades::RESULTS, fn (Blueprint $table) => $table->dropColumn('definitiva_planilla'));
        Permission::where('name', GradeSourceDecisions::CHOOSE)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
