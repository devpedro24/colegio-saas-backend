<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Propaga el catalogo RBAC central a las bases aisladas de los tenants. */
class TenantRbacSynchronizer
{
    /**
     * Politica deliberada: RbacSeeder no elimina roles/permisos Spatie que ya
     * no esten en el catalogo. Se preservan para no romper asignaciones de
     * usuarios; simplemente dejan de recibir grants desde la matriz central.
     */
    public const OBSOLETE_POLICY = 'preserve_assignments';

    /**
     * @return array{attempted:int,synced:list<string>,failed:array<string,string>,policy:string}
     *
     * @throws TenantRbacSynchronizationException
     */
    public function syncAll(?string $planKey = null): array
    {
        $result = $this->syncAllWithResult($planKey);

        if ($result['failed'] !== []) {
            throw new TenantRbacSynchronizationException($result);
        }

        return $result;
    }

    /**
     * Variante no lanzable para compensacion y reportes de operacion.
     *
     * @return array{attempted:int,synced:list<string>,failed:array<string,string>,policy:string}
     */
    public function syncAllWithResult(?string $planKey = null): array
    {
        $query = Tenant::query()->where('status', '!=', Tenant::STATUS_DELETED);
        if ($planKey !== null) {
            $query->where('plan', $planKey);
        }

        $synced = [];
        $errors = [];

        try {
            $tenants = $query->orderBy('id')->get();
        } catch (Throwable $exception) {
            return [
                'attempted' => 0,
                'synced' => [],
                'failed' => ['central' => $exception->getMessage()],
                'policy' => self::OBSOLETE_POLICY,
            ];
        }

        $tenants->each(function (Tenant $tenant) use (&$synced, &$errors): void {
            try {
                // Cada tenant es atomico aunque la operacion distribuida no
                // pueda compartir una unica transaccion entre bases fisicas.
                $tenant->run(fn () => DB::transaction(
                    fn () => (new RbacSeeder)->run(),
                ));
                $synced[] = (string) $tenant->id;
            } catch (Throwable $exception) {
                $errors[(string) $tenant->id] = $exception->getMessage();
            }
        });

        return [
            'attempted' => $tenants->count(),
            'synced' => $synced,
            'failed' => $errors,
            'policy' => self::OBSOLETE_POLICY,
        ];
    }
}
