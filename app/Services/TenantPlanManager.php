<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Cambia el plan de una familia colegio/sedes como una sola unidad logica. */
final class TenantPlanManager
{
    /**
     * @return array{previous:string,current:string,tenants:list<string>}
     */
    public function change(Tenant $tenant, string $planKey): array
    {
        if ($tenant->tipo === Tenant::TIPO_SEDE || $tenant->parent_id !== null) {
            throw new RuntimeException('El plan solo puede cambiarse desde el colegio principal.');
        }

        $plan = Plan::query()->where('key', $planKey)->where('is_active', true)->first();
        if ($plan === null) {
            throw new RuntimeException('El plan seleccionado no existe o esta inactivo.');
        }

        $this->assertCapacity($tenant, $plan);

        $centralName = (string) config('tenancy.database.central_connection');
        $previousByTenant = [];
        $ids = [];

        DB::connection($centralName)->transaction(function () use ($tenant, $planKey, &$previousByTenant, &$ids): void {
            $family = Tenant::query()
                ->where(fn ($query) => $query->whereKey($tenant->id)->orWhere('parent_id', $tenant->id))
                ->lockForUpdate()
                ->get();

            foreach ($family as $member) {
                $previousByTenant[(string) $member->id] = (string) $member->plan;
                $ids[] = (string) $member->id;
                $member->update(['plan' => $planKey]);
            }
        });

        try {
            $this->syncIds($ids);
        } catch (Throwable $exception) {
            DB::connection($centralName)->transaction(function () use ($previousByTenant): void {
                foreach ($previousByTenant as $id => $previous) {
                    Tenant::query()->whereKey($id)->update(['plan' => $previous]);
                }
            });

            // Compensacion de RBAC: re-siembra el catalogo con el plan anterior.
            try {
                $this->syncIds(array_keys($previousByTenant));
            } catch (Throwable) {
                // El error original conserva la causa; el comando rbac:sync
                // permite reparar una sincronizacion interrumpida.
            }

            throw new RuntimeException(
                'No se pudo sincronizar el plan en todas las sedes; el cambio fue revertido.',
                previous: $exception,
            );
        }

        return [
            'previous' => (string) ($previousByTenant[(string) $tenant->id] ?? $tenant->plan),
            'current' => $planKey,
            'tenants' => $ids,
        ];
    }

    public function assertCapacity(Tenant $tenant, Plan $plan): void
    {
        $children = Tenant::query()
            ->where('parent_id', $tenant->id)
            ->whereNotIn('status', [Tenant::STATUS_IN_RETENTION, Tenant::STATUS_DELETED])
            ->count();
        $total = 1 + $children;
        $features = $plan->features ?? [];

        if ($children > 0 && ! in_array('multi_sede', $features, true)) {
            throw new RuntimeException('El plan seleccionado no incluye multi_sede y el colegio ya tiene sedes hijas.');
        }

        if ($plan->max_sedes !== null && $total > $plan->max_sedes) {
            throw new RuntimeException("El colegio usa {$total} sedes y el plan solo permite {$plan->max_sedes}.");
        }
    }

    /** @param list<string> $ids */
    private function syncIds(array $ids): void
    {
        foreach ($ids as $id) {
            $member = Tenant::find($id);
            if ($member === null || $member->status === Tenant::STATUS_DELETED) {
                continue;
            }

            $member->run(fn () => (new RbacSeeder)->run());
        }
    }
}
