<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ConfiguracionHeredada;
use App\Events\PlatformDataChanged;
use App\Events\SedeCreada;
use App\Events\TenantChanged;
use App\Events\TenantDataChanged;
use App\Support\Realtime\ModelChanges;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RealtimeChanges::class);
    }

    public function boot(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            Event::listen('eloquent.'.$event.': *', ModelChanges::class);
        }
        Event::listen(TenantDataChanged::class, function ($event): void {
            if ($event->tenantId === null) return;
            $resource = match ($event->entity) {
                'ano_lectivo', 'periodo' => 'academic',
                'sede', 'jornada', 'nivel', 'grado', 'grupo', 'bloque_horario', 'espacio_fisico' => 'structure',
                'area', 'materia' => 'curriculum',
                'usuario' => 'users',
                'datos_institucionales' => 'institution',
                'escala', 'metodo', 'modelo' => 'academic-config',
                default => 'all',
            };
            app(RealtimeChanges::class)->record($event->tenantId, $resource);
        });
        Event::listen(TenantChanged::class, fn ($event) => app(RealtimeChanges::class)->record($event->tenantId, 'access'));
        Event::listen(PlatformDataChanged::class, fn ($event) => app(RealtimeChanges::class)->record(null,
            ['colegios' => 'schools', 'plans' => 'plans', 'rbac' => 'rbac'][$event->resource] ?? 'all'));
        Event::listen(PlatformDataChanged::class, function ($event): void {
            if (! in_array($event->resource, ['rbac', 'plans'], true)) return;
            foreach (\App\Models\Tenant::query()->whereNotIn('status', ['provisioning', 'deleted'])->cursor() as $tenant) {
                \App\Jobs\SynchronizeTenantPermissions::dispatch((string) $tenant->getKey())->afterCommit();
            }
        });
        Event::listen(SedeCreada::class, fn ($event) => app(RealtimeChanges::class)->record($event->colegioId, 'structure'));
        Event::listen(ConfiguracionHeredada::class, function ($event): void {
            app(RealtimeChanges::class)->record($event->colegioId, 'all');
            app(RealtimeChanges::class)->record($event->tenantId, 'all');
        });
    }
}
