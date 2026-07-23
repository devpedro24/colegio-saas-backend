<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CU-001 — Onboardear nuevo colegio.
 *
 * Provisiona un tenant completo:
 *  1. Crea el registro del colegio (estado `configuring`).
 *  2. El evento TenantCreated dispara CreateDatabase + MigrateDatabase
 *     (crea la BD PostgreSQL aislada `tenant<uuid>` y corre sus migraciones).
 *  3. Registra el subdominio `<slug>` en la tabla `domains`.
 *  4. Crea el usuario rector con contrasena temporal (RN-AU-360).
 *
 * Solo el superadministrador ejecuta esto (no hay self-service, RN-T-001).
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Nombre del colegio}
        {slug : Subdominio del colegio (ej: colegio-san-jose)}
        {rector_email : Correo institucional del rector}
        {--rector-name=Rector : Nombre del rector}
        {--plan=esencial : Plan comercial (esencial|estandar|premium)}';

    protected $description = 'Provisiona un nuevo colegio (tenant): BD aislada, migraciones y usuario rector (CU-001)';

    public function handle(): int
    {
        $slug = Str::slug($this->argument('slug'));

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Ya existe un colegio con el slug '{$slug}'.");

            return self::FAILURE;
        }

        $tempPassword = Str::password(14);

        $this->info("Provisionando colegio '{$this->argument('name')}' (slug: {$slug})...");

        // 1 + 2. Crear tenant -> el evento TenantCreated crea y migra la BD aislada.
        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'name' => $this->argument('name'),
            'slug' => $slug,
            'plan' => $this->option('plan'),
            'status' => Tenant::STATUS_CONFIGURING,
        ]);

        // 3. Registrar el subdominio del colegio.
        $tenant->domains()->create(['domain' => $slug]);

        // 4. Crear el usuario rector DENTRO de la BD del tenant.
        $tenant->run(function () use ($tempPassword) {
            User::create([
                'name' => $this->option('rector-name'),
                'email' => $this->argument('rector_email'),
                'password' => Hash::make($tempPassword),
                'role' => 'rector',
                'status' => 'active',
                'must_change_password' => true,
            ]);
        });

        $this->newLine();
        $this->info('Colegio provisionado correctamente.');
        $this->table(['Campo', 'Valor'], [
            ['Tenant ID (UUID)', $tenant->id],
            ['Base de datos', 'tenant'.$tenant->id],
            ['Subdominio', $slug.'.localhost'],
            ['Rector', $this->argument('rector_email')],
            ['Contrasena temporal', $tempPassword],
        ]);
        $this->warn('Entrega la contrasena temporal al rector: debe cambiarla en el primer ingreso (RN-AU-360).');

        return self::SUCCESS;
    }
}
