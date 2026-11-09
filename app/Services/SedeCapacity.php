<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SedeCapacity
{
    public static function enrolled(?Sede $sede): int
    {
        $count = fn () => User::where('role', 'estudiante')->where('status', 'active')->count();
        if ($sede?->tenant_id) {
            $child = Tenant::find($sede->tenant_id);

            return $child ? $child->run($count) : 0;
        }

        return $count();
    }

    /** Existing campuses reserve at least their current active enrollment. */
    public static function summary(?Sede $except = null): array
    {
        $allocated = 0;
        foreach (Sede::all() as $sede) {
            if ($except && $sede->id === $except->id) {
                continue;
            }
            $allocated += max($sede->capacidad_maxima ?? 0, self::enrolled($sede));
        }
        $limit = SchoolSubscription::effective(tenant()->fresh())?->max_estudiantes;

        return ['contratados' => $limit, 'asignados' => $allocated,
            'disponibles' => $limit === null ? null : max(0, $limit - $allocated)];
    }

    /** Called while holding the central school row lock. */
    public static function validate(int $capacity, ?Sede $sede = null): void
    {
        $summary = self::summary($sede);
        if ($summary['disponibles'] !== null && $capacity > $summary['disponibles']) {
            throw ValidationException::withMessages(['capacidad_maxima' => "Quedan {$summary['disponibles']} cupos disponibles de {$summary['contratados']} contratados. Ajusta la capacidad o contacta al administrador de la plataforma para ampliarla."]);
        }
        if ($sede && $capacity < self::enrolled($sede)) {
            throw ValidationException::withMessages(['capacidad_maxima' => 'La capacidad debe cubrir los estudiantes activos de esta sede.']);
        }
    }
}
