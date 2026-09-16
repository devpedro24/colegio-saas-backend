<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantProvisioner;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * CU-001 — Onboardear nuevo colegio (por consola).
 *
 * Delega el alta al servicio TenantProvisioner (misma logica que usa la API del
 * superadministrador): crea el tenant + BD aislada, siembra el RBAC y crea el
 * usuario rector con contrasena temporal (RN-AU-360).
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Nombre del colegio}
        {slug : Subdominio del colegio (ej: colegio-san-jose)}
        {rector_email : Correo institucional del rector}
        {--rector-name=Rector : Nombre del rector}
        {--plan=esencial : Plan comercial (esencial|estandar|premium)}';

    protected $description = 'Provisiona un nuevo colegio (tenant): BD aislada, RBAC y usuario rector (CU-001)';

    public function handle(TenantProvisioner $provisioner): int
    {
        $this->info("Provisionando colegio '{$this->argument('name')}'...");

        try {
            ['tenant' => $tenant, 'password' => $tempPassword] = $provisioner->provision([
                'name' => (string) $this->argument('name'),
                'slug' => (string) $this->argument('slug'),
                'rector_email' => (string) $this->argument('rector_email'),
                'rector_name' => (string) $this->option('rector-name'),
                'plan' => (string) $this->option('plan'),
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Colegio provisionado correctamente.');
        $this->table(['Campo', 'Valor'], [
            ['Tenant ID', $tenant->id],
            ['Base de datos', $tenant->getInternal('db_name') ?? TenantDatabaseName::for($tenant)],
            ['Subdominio', $tenant->slug.'.'.config('tenancy.tenant_base_domain', 'localhost')],
            ['Rector', (string) $this->argument('rector_email')],
            ['Contrasena temporal', $tempPassword],
        ]);
        $this->warn('Entrega la contrasena temporal al rector: debe cambiarla en el primer ingreso (RN-AU-360).');

        return self::SUCCESS;
    }
}
