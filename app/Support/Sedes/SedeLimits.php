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
    /** Limite de sedes del plan vigente del colegio (null = ilimitado). */
    public static function maxSedes(): ?int
    {
        $tenant = tenancy()->tenant;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return Plan::where('key', $tenant->plan)->value('max_sedes');
    }

    /**
     * ¿Se llego al limite del plan? Cuenta sedes ACTIVAS (sin soft-delete):
     * una sede eliminada libera su cupo.
     */
    public static function alLimite(): bool
    {
        $max = self::maxSedes();

        if ($max === null) {
            return false;
        }

        return Sede::query()->count() >= $max;
    }
}