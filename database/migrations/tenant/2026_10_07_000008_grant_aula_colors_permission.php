<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        $configuration = Permission::findOrCreate('aula.configurar', 'web');
        Role::whereIn('name', ['coord_academico', 'coord_combinado'])->where('guard_name', 'web')
            ->get()->each(fn (Role $role) => $role->revokePermissionTo($configuration));

        $appearance = Permission::findOrCreate('aula.apariencia.configurar', 'web');
        Role::where('name', 'rector')->where('guard_name', 'web')->first()?->givePermissionTo($appearance);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
