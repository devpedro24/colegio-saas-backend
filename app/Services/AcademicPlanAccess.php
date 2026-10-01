<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;

final class AcademicPlanAccess
{
    public function preinformes(): bool
    {
        $planKey = tenant()?->fresh()?->plan;

        return $planKey && in_array('preinformes', Plan::where('key', $planKey)->value('features') ?? [], true);
    }

    public function requirePreinformes(): void
    {
        abort_unless($this->preinformes(), 403, 'El plan del colegio no incluye preinformes. Están disponibles desde Estándar o en un plan personalizado que los incluya.');
    }
}
