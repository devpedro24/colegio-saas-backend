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

    public function aula(): bool
    {
        $planKey = tenant()?->fresh()?->plan;

        return $planKey && in_array('aula', Plan::where('key', $planKey)->value('features') ?? [], true);
    }

    public function requireAula(): void
    {
        abort_unless($this->aula(), 403, 'El plan del colegio no incluye Aula.');
    }

    public function customBulletins(): bool
    {
        $planKey = tenant()?->fresh()?->plan;

        return $planKey && in_array('boletines_personalizables', Plan::where('key', $planKey)->value('features') ?? [], true);
    }

    public function requireCustomBulletins(): void
    {
        abort_unless($this->customBulletins(), 403, 'El plan del colegio no incluye edición de plantillas de boletín.');
    }

    public function aulaColors(): bool
    {
        $planKey = tenant()?->fresh()?->plan;
        $features = $planKey ? Plan::where('key', $planKey)->value('features') ?? [] : [];

        return in_array('aula', $features, true) && in_array('aula_colores', $features, true);
    }
}
