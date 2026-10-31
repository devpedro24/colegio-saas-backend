<?php

declare(strict_types=1);

namespace App\Services;

final class AcademicPlanAccess
{
    public function preinformes(): bool
    {
        return app(TenantPlanAccess::class)->has('preinformes');
    }

    public function requirePreinformes(): void
    {
        abort_unless($this->preinformes(), 403, 'El plan del colegio no incluye preinformes. Están disponibles desde Estándar o en un plan personalizado que los incluya.');
    }

    public function aula(): bool
    {
        return app(TenantPlanAccess::class)->has('aula');
    }

    public function requireAula(): void
    {
        abort_unless($this->aula(), 403, 'El plan del colegio no incluye Aula.');
    }

    public function customBulletins(): bool
    {
        return app(TenantPlanAccess::class)->has('boletines_personalizables');
    }

    public function requireCustomBulletins(): void
    {
        abort_unless($this->customBulletins(), 403, 'El plan del colegio no incluye edición de plantillas de boletín.');
    }

    public function aulaColors(): bool
    {
        $access = app(TenantPlanAccess::class);

        return $access->has('aula') && $access->has('aula_colores');
    }
}
