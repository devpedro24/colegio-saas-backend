<?php

declare(strict_types=1);

namespace App\Support\Sedes;

use App\Models\Academico\Sede;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Limites de sedes del plan del colegio (gating SaaS).
 *
 * El plan vive en la BD central (Plan fuerza la conexion central) y el colegio
 * se obtiene del tenancy inicializado. `max_sedes = null` significa ilimitado.
 */
class SedeLimits
{
    public const FEATURE = 'multi_sede';

    /** Limite de sedes del plan vigente del colegio (null = ilimitado). */
    public static function maxSedes(?Tenant $tenant = null): ?int
    {
        $tenant ??= tenancy()->tenant;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return Plan::where('key', $tenant->plan)->first()?->max_sedes;
    }

    public static function permiteMultiSede(?Tenant $tenant = null): bool
    {
        $tenant ??= tenancy()->tenant;

        if (! $tenant instanceof Tenant || $tenant->tipo === Tenant::TIPO_SEDE) {
            return false;
        }

        $features = Plan::where('key', $tenant->plan)->first()?->features ?? [];

        return is_array($features) && in_array(self::FEATURE, $features, true);
    }

    /**
     * ¿Se llego al limite del plan? Cuenta sedes ACTIVAS (sin soft-delete):
     * una sede eliminada libera su cupo.
     */
    public static function alLimite(?Tenant $tenant = null): bool
    {
        if (! self::permiteMultiSede($tenant)) {
            return true;
        }

        $max = self::maxSedes($tenant);

        if ($max === null) {
            return false;
        }

        return Sede::query()->count() >= $max;
    }

    public static function mensaje(?Tenant $tenant = null): string
    {
        if (! self::permiteMultiSede($tenant)) {
            return 'El plan vigente no incluye la funcionalidad multi_sede.';
        }

        $max = self::maxSedes($tenant);

        return $max === null
            ? 'El plan vigente permite sedes ilimitadas.'
            : 'El plan del colegio permite maximo '.$max.' sede(s), incluida la principal.';
    }

    /**
     * Verificacion central, util antes de crear el tenant hijo. El total
     * comercial incluye al colegio principal y a sus hijos no terminales.
     */
    public static function centralViolation(Tenant $tenant): ?string
    {
        if (! self::permiteMultiSede($tenant)) {
            return self::mensaje($tenant);
        }

        $max = self::maxSedes($tenant);
        if ($max === null) {
            return null;
        }

        $children = Tenant::query()
            ->where('parent_id', $tenant->id)
            ->whereNotIn('status', [Tenant::STATUS_IN_RETENTION, Tenant::STATUS_DELETED])
            ->count();

        return (1 + $children) >= $max ? self::mensaje($tenant) : null;
    }
}
