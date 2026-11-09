<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Plans\PlanCatalog;
use Illuminate\Database\Seeder;

/**
 * Siembra los dos planes comerciales por defecto (Estándar / Premium)
 * con sus features y limites segun App\Plans\PlanCatalog.
 *
 * Idempotente: usa firstOrCreate por `key`; respeta tarifas y funciones ya configuradas.
 * Solo corre en la BD CENTRAL (no dentro de un tenant).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PlanCatalog::defaultPlans() as $plan) {
            Plan::firstOrCreate(
                ['key' => $plan['key']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'is_active' => true,
                    'max_estudiantes' => $plan['max_estudiantes'],
                    'storage_gb' => $plan['storage_gb'],
                    'max_sedes' => $plan['max_sedes'],
                    'max_pasarelas' => $plan['max_pasarelas'],
                    'max_roles_personalizados' => $plan['max_roles_personalizados'],
                    'features' => $plan['features'],
                    'pricing' => \App\Plans\PlanPricing::defaults($plan['key']),
                    'sort_order' => $plan['sort_order'],
                ],
            );
        }
    }
}
