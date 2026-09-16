<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
        if (User::isImpersonationShadowEmail($data['rector_email'] ?? null)) {
            throw new RuntimeException('El correo del rector pertenece a un namespace tecnico reservado.');
        }

        $slug = Str::slug($data['slug']);

        if (Tenant::where('slug', $slug)->exists()) {
            throw new RuntimeException("Ya existe un colegio con el slug '{$slug}'.");
        }

        $tempPassword = Str::password(14);

        $tenant = null;

        try {
            /** @var Tenant $tenant */
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'legal_name' => $data['legal_name'] ?? null,
                'nit' => $data['nit'] ?? null,
                'plan' => $data['plan'] ?? Tenant::PLAN_ESENCIAL,
                'status' => Tenant::STATUS_PROVISIONING,
            ]);

            // Registra el subdominio del colegio.
            $tenant->domains()->create(['domain' => $slug]);

            // Los datos internos del tenant si son atomicos. La creacion fisica
            // de la BD es externa a esta transaccion y se compensa en el catch.
            $tenant->run(function () use ($data, $tempPassword): void {
                DB::transaction(function () use ($data, $tempPassword): void {
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
            });

            $tenant->update(['status' => Tenant::STATUS_CONFIGURING]);
        } catch (Throwable $exception) {
            $this->compensate($tenant, $slug, $exception);

            throw new RuntimeException(
                'No fue posible provisionar el colegio. No se conservaron recursos parciales.',
                previous: $exception,
            );
        }

        return ['tenant' => $tenant, 'password' => $tempPassword];
    }

    private function compensate(?Tenant $tenant, string $slug, Throwable $cause): void
    {
        try {
            // Tenant::delete dispara el pipeline TenantDeleted/DeleteDatabase.
            // La busqueda cubre fallos ocurridos durante Tenant::create(), una
            // vez insertada la fila central pero antes de devolver el modelo.
            $partial = $tenant ?? Tenant::where('slug', $slug)->first();
            if ($partial !== null) {
                $partial->domains()->delete();
                $partial->delete();
            }
        } catch (Throwable $cleanup) {
            Log::critical('Fallo la compensacion de provisioning de tenant.', [
                'slug' => $slug,
                'cause' => $cause->getMessage(),
                'cleanup_error' => $cleanup->getMessage(),
            ]);
        }
    }
}
