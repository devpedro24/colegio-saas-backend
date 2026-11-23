<?php

use App\Services\Ingreso\EarlyPromotionCertificates;
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
        Schema::create(EarlyPromotionCertificates::TABLE, function (Blueprint $t) {
            $t->bigIncrements('consecutivo');
            $t->foreignId('promocion_id')->unique()->constrained(EarlyPromotions::TABLE)->restrictOnDelete();
            $t->foreignId('firmante_id')->constrained('users')->restrictOnDelete();
            $t->string('firmante');
            $t->date('fecha_expedicion');
            $t->timestampTz('emitido_en');
            $t->string('pdf_path')->nullable();
            $t->char('pdf_sha256', 64)->nullable();
        });
        $permission = Permission::findOrCreate(EarlyPromotionCertificates::ISSUE, 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table(EarlyPromotionCertificates::TABLE)->exists()) {
            throw new RuntimeException('La tabla contiene constancias emitidas. Conserva el archivo documental.');
        }
        Schema::dropIfExists(EarlyPromotionCertificates::TABLE);
        Permission::where('name', EarlyPromotionCertificates::ISSUE)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
