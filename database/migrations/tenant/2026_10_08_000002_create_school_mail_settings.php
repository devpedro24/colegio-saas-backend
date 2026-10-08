<?php

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
        Schema::create('correo_configuracion', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('email');
            $t->string('nombre', 120);
            $t->text('app_password');
            $t->timestamp('verificado_en');
            $t->timestamps();
        });
        $p = Permission::findOrCreate('config.correo', 'web');
        Role::where('name', 'rector')->first()?->givePermissionTo($p);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_configuracion');
    }
};
