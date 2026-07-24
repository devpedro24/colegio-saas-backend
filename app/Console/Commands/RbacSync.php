<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;

/**
 * Propaga el catalogo RBAC central (rbac_*) a los colegios (tenants) existentes.
 *
 * Re-siembra spatie en cada tenant desde el catalogo + su plan, PRESERVANDO las
 * elecciones de configurables del rector (firstSeed=false). Crea permisos/roles
 * nuevos y aplica el gating por plan.
 *
 *   php artisan rbac:sync              # todos los colegios
 *   php artisan rbac:sync --tenant=slug
 */
class RbacSync extends Command
{
    protected $signature = 'rbac:sync {--tenant= : Slug de un colegio especifico}';

    protected $description = 'Sincroniza el catalogo RBAC central a los colegios (tenants).';

    public function handle(): int
    {
        $query = Tenant::query();

        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay colegios para sincronizar.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $tenant->run(fn () => (new RbacSeeder())->run());
            $this->info("RBAC sincronizado: {$tenant->slug} (plan: {$tenant->plan})");
        }

        $this->info("Listo. {$tenants->count()} colegio(s) sincronizado(s).");

        return self::SUCCESS;
    }
}
