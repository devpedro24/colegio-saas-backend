<?php

declare(strict_types=1);

use App\Models\Rbac\RbacMatrixCell;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // A tenant-only migration must never invent grants without its central catalog.
        if (! (new RbacMatrixCell)->getConnection()->getSchemaBuilder()->hasTable('rbac_matrix')) {
            return;
        }

        $permission = Permission::findOrCreate('boletines.observaciones.gestionar', 'web');
        foreach (['docente', 'director_grupo'] as $roleKey) {
            $cell = RbacMatrixCell::where('role_key', $roleKey)
                ->where('permission_key', $permission->name)->first();
            if ($cell?->type === 'configurable' && $cell->default_granted) {
                Role::where('name', $roleKey)->where('guard_name', 'web')
                    ->first()?->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
