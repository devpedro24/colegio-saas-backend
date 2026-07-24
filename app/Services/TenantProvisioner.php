<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisiona un colegio (tenant) completo (CU-001):
 * crea el registro + BD aislada, siembra el RBAC y crea el usuario rector.
 *
 * Lo usan tanto el comando `tenant:create` como la API del superadministrador,
 * para que la logica de alta viva en un solo lugar.
 */
class TenantProvisioner
{
    /**
     * @param  array{name:string, slug:string, rector_email:string, rector_name?:?string, plan?:?string, legal_name?:?string, nit?:?string}  $data
     * @return array{tenant: Tenant, password: string}
     */
    public function provision(array $data): array
    {
        $slug = Str::slug($data['slug']);

        if (Tenant::where('slug', $slug)->exists()) {
            throw new RuntimeException("Ya existe un colegio con el slug '{$slug}'.");
        }

        $tempPassword = Str::password(14);

        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $slug,
            'legal_name' => $data['legal_name'] ?? null,
            'nit' => $data['nit'] ?? null,
            'plan' => $data['plan'] ?? Tenant::PLAN_ESENCIAL,
            'status' => Tenant::STATUS_CONFIGURING,
        ]);

        // Registra el subdominio del colegio.
        $tenant->domains()->create(['domain' => $slug]);

        // Dentro de la BD del colegio: RBAC (sembrado desde central + plan) + rector.
        $tenant->run(function () use ($data, $tempPassword) {
            (new RbacSeeder(firstSeed: true))->run();

            $rector = User::create([
                'name' => $data['rector_name'] ?? 'Rector',
                'email' => $data['rector_email'],
                'password' => Hash::make($tempPassword),
                'role' => 'rector',
                'status' => 'active',
                'must_change_password' => true,
            ]);

            $rector->assignRole('rector');
        });

        return ['tenant' => $tenant, 'password' => $tempPassword];
    }
}
