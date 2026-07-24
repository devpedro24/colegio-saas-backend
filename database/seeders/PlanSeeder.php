<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Plans\PlanCatalog;
use Illuminate\Database\Seeder;

/**
 * Siembra los 3 planes comerciales por defecto (Esencial / Estandar / Premium)
 * con sus features y limites segun App\Plans\PlanCatalog.
 *
 * Idempotente: usa updateOrCreate por `key`, asi que reejecutar no duplica.
 * Solo corre en la BD CENTRAL (no dentro de un tenant).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PlanCatalog::defaultPlans() as $plan) {
            Plan::updateOrCreate(
                ['key' => $plan['key']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'is_active' => true,
                    'max_estudiantes' => $plan['max_estudiantes'],
                    'storage_gb' => $plan['storage_gb'],
                    'max_sedes' => $plan['max_sedes'],
                    'max_pasarelas' => $plan['max_pasarelas'],
                    'features' => $plan['features'],
                    'sort_order' => $plan['sort_order'],
                ],
            );
        }
    }
}
