<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/** No aplica un downgrade que deje sedes existentes fuera del plan. */
final class SchoolPlanSedeGuard
{
    public static function ensureSchoolFits(Tenant $school, Plan $plan): void
    {
        if (is_array($school->commercial)) {
            $plan = SchoolSubscription::effective($school) ?? $plan;
        }
        self::ensureCapacity($school, $plan->features ?? [], $plan->max_sedes);
    }

    /** Al editar un plan se revisan todos sus colegios, no solo uno. */
    public static function ensureSubscribersFit(string $planKey, array $features, ?int $maxSedes): void
    {
        Tenant::on(config('tenancy.database.central_connection'))
            ->where('plan', $planKey)->whereNull('parent_id')
            ->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])
            ->cursor()->each(function (Tenant $school) use ($features, $maxSedes): void {
                if (! is_array($school->commercial)) {
                    self::ensureCapacity($school, $features, $maxSedes);
                }
            });
    }

    private static function ensureCapacity(Tenant $school, array $features, ?int $maxSedes): void
    {
        $active = 1 + Tenant::on(config('tenancy.database.central_connection'))
            ->where('parent_id', $school->getTenantKey())
            ->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])
            ->count();
        $allowed = in_array('multi_sede', $features, true) ? $maxSedes : 1;
        if ($allowed !== null && $active > $allowed) {
            throw ValidationException::withMessages(['plan' => "El colegio {$school->name} tiene {$active} sedes; el plan permite {$allowed}. Resuelve las sedes activas antes de cambiar el plan."]);
        }
    }
}
