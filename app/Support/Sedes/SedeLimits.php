<?php

declare(strict_types=1);

namespace App\Support\Sedes;

use App\Models\Academico\Sede;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            throw ValidationException::withMessages(['plan' => 'No hay un colegio activo.']);
        }

        if ($tenant->tipo === Tenant::TIPO_SEDE) {
            throw ValidationException::withMessages(['plan' => 'Las sedes se administran desde el colegio principal.']);
        }
        $plan = Plan::where('key', $tenant->fresh()->plan)->first();
        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'El colegio no tiene un plan válido asignado.']);
        }

        return $plan->max_sedes;
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

    /** Reserva una fila antes de provisionar; serializa ambas vías de alta. */
    public static function create(array $attributes): Sede
    {
        $central = DB::connection(config('tenancy.database.central_connection'));

        return $central->transaction(function () use ($central, $attributes) {
            $central->table('tenants')->where('id', tenant()->getKey())->lockForUpdate()->first();
            if (self::alLimite()) {
                throw ValidationException::withMessages(['plan' => 'Se alcanzó el límite de '.self::maxSedes().' sedes del plan.']);
            }

            return Sede::create($attributes);
        });
    }
}
