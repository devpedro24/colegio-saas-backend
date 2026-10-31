<?php

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

        foreach (['boletines.plantillas.ver', 'boletines.emisiones.ver', 'boletines.emisiones.preparar', 'boletines.emisiones.publicar'] as $key) {
            $permission = Permission::findOrCreate($key, 'web');
            foreach (['docente', 'director_grupo'] as $role) {
                $cell = RbacMatrixCell::where('role_key', $role)->where('permission_key', $key)->first();
                if ($cell?->type === 'configurable' && $cell->default_granted) {
                    Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
