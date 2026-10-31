<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['boletines.grupo.ver', 'indicadores.grupo.ver'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (['docente', 'director_grupo'] as $roleKey) {
                Role::where('name', $roleKey)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
