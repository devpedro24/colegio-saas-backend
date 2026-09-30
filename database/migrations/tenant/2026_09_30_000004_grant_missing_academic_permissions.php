<?php

declare(strict_types=1);

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const KEYS = [
        'academico.anos.transicionar',
        'academico.periodos.transicionar',
        'academico.plan_estudios.gestionar',
    ];

    public function up(): void
    {
        foreach (PermissionMatrix::permissions() as $entry) {
            if (! in_array($entry['key'], self::KEYS, true)) {
                continue;
            }

            $permission = Permission::findOrCreate($entry['key'], 'web');
            foreach ($entry['cells'] as $roleKey => $cell) {
                $policy = PermissionMatrix::classifyCell($cell);
                if (! $policy['default']) {
                    continue;
                }

                $role = Role::where('name', $roleKey)->where('guard_name', 'web')->first();
                $role?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preservar permisos y asignaciones preexistentes al revertir.
    }
};
