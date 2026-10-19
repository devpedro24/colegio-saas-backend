<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Ningún rol escolar recibe este permiso inicialmente. La suplantación
        // del superadministrador usa Gate::before; el rector puede delegarlo.
        Permission::findOrCreate('estudiantes.egreso.gestionar', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
