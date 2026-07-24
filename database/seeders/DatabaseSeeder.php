<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Catalogo central: planes + RBAC (roles/permisos/matriz).
        $this->call([
            PlanSeeder::class,
            RbacCatalogSeeder::class,
        ]);
    }
}
