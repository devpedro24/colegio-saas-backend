<?php

use App\Services\Ingreso\EarlyPromotions;
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
        Schema::create(EarlyPromotions::TABLE, function (Blueprint $t) {
            $t->id();
            $t->char('token', 24)->unique();
            $t->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $t->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $t->foreignId('grupo_destino_id')->constrained('grupos')->restrictOnDelete();
            $t->unsignedInteger('version_origen');
            $t->string('origen');
            $t->string('destino');
            $t->string('estado');
            $t->date('fecha_consentimiento');
            $t->char('consentimiento_token', 24);
            $t->string('consentimiento_nombre');
            $t->date('fecha_recomendacion');
            $t->text('recomendacion');
            $t->foreignId('recomendador_id')->constrained('users')->restrictOnDelete();
            $t->string('recomendado_por');
            $t->char('huella', 64);
            $t->timestampTz('registrado_en');
            $t->date('fecha_decision')->nullable();
            $t->string('acta_numero')->nullable();
            $t->char('acta_token', 24)->nullable();
            $t->string('acta_nombre')->nullable();
            $t->foreignId('revisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decidido_por')->nullable();
            $t->char('huella_decision', 64)->nullable();
            $t->timestampTz('decidido_en')->nullable();
            $t->foreignId('movimiento_id')->nullable()->unique()->constrained('matricula_movimientos')->restrictOnDelete();
            $t->unique(['matricula_id', 'huella']);
        });
        DB::statement("CREATE UNIQUE INDEX promocion_pendiente_por_matricula ON matricula_promociones_anticipadas (matricula_id) WHERE estado = 'recomendada'");
        foreach ([EarlyPromotions::RECOMMEND, EarlyPromotions::DECIDE] as $key) {
            $permission = Permission::findOrCreate($key, 'web');
            Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table(EarlyPromotions::TABLE)->exists()) {
            throw new RuntimeException('Existen expedientes de promoción anticipada. Conserva el registro escolar antes de revertir.');
        }
        Schema::dropIfExists(EarlyPromotions::TABLE);
        Permission::whereIn('name', [EarlyPromotions::RECOMMEND, EarlyPromotions::DECIDE])->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
