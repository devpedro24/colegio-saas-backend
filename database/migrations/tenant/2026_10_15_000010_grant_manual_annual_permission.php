<?php

declare(strict_types=1);

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionMatrix::permissions() as $entry) {
            if ($entry['key'] !== 'notas.resultados_anuales.gestionar') continue;
            $permission = Permission::findOrCreate($entry['key'], 'web');
            foreach ($entry['cells'] as $role => $value) {
                if (PermissionMatrix::classifyCell($value)['default']) {
                    Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
