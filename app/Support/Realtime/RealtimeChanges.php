<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Events\ApplicationChanged;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** One notification per affected school and HTTP request, after committed writes. */
final class RealtimeChanges
{
    private bool $buffering = false;
    private array $pending = [];

    public function begin(): void
    {
        $this->pending = [];
        $this->buffering = true;
    }

    public function record(?string $tenantId, string $resource = 'all', ?Connection $connection = null): void
    {
        // Capture the tenant NOW: child-school operations can restore another context later.
        $enqueue = function () use ($tenantId, $resource): void {
            if ($this->buffering) {
                $key = $tenantId === null ? 'platform' : 'tenant:'.$tenantId;
                $this->pending[$key]['tenant'] = $tenantId;
                $this->pending[$key]['resources'][$resource] = true;
            } else {
                $this->publish($tenantId, [$resource]);
            }
        };
        $connection ??= DB::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($enqueue);
        } else {
            $enqueue();
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->buffering = false;
        foreach ($pending as $change) {
            $this->publish($change['tenant'], array_keys($change['resources']));
        }
    }

    private function publish(?string $tenantId, array $resources): void
    {
        try {
            ApplicationChanged::dispatch($tenantId, $resources);
        } catch (\Throwable $error) {
            // The write is committed; an unavailable socket must not report a failed save.
            Log::warning('Realtime notification failed', ['tenant' => $tenantId, 'resources' => $resources, 'error' => $error->getMessage()]);
        }
    }

    public static function resourceForPath(string $path): string
    {
        if (preg_match('#^/?(?:api/)?siee/[^/]+/curriculo(?:/|$|\?)#', $path)) {
            return 'curriculum';
        }
        // Las aperturas y el autosalvado son privados del estudiante. No deben
        // invalidar los catálogos del colegio ni provocar una recarga de /me.
        if (preg_match('#^/?(?:api/)?aula/recursos/[^/]+/abrir(?:/|$|\?)#', $path)) {
            return 'aula-progress';
        }
        if (preg_match('#^/?(?:api/)?aula/(?:(?:recursos|entregas)/[^/]+/adjuntos|adjuntos/[^/]+)(?:/|$|\?)#', $path)) {
            return 'aula-content';
        }
        if (preg_match('#^/?(?:api/)?aula/intentos/[^/]+/(?:respuestas|pagina|incidentes)(?:/|$|\?)#', $path)) {
            return 'aula-attempt';
        }
        if (preg_match('#^/?(?:api/)?aula/(?:secciones/[^/]+/recursos|recursos/[^/]+|entregas/[^/]+/calificar|intentos/[^/]+/(?:finalizar|calificar))(?:$|\?)#', $path)) {
            return 'aula-grade';
        }
        $root = explode('/', trim(preg_replace('#^/?api/#', '', $path), '/'))[0];
        return match ($root) {
            'anos-lectivos', 'periodos' => 'academic',
            'estructura' => 'structure',
            'plan-estudios' => 'curriculum',
            'horarios', 'asignaciones' => 'schedule',
            'asistencias' => 'attendance',
            'evaluacion' => 'evaluation',
            'aula' => 'aula',
            'siee', 'config' => 'academic-config',
            'eventos' => 'events',
            'onboarding', 'branding' => 'institution',
            'usuarios' => 'users',
            'rbac' => 'rbac',
            'colegios' => 'schools',
            'plans', 'planes' => 'plans',
            'account', 'mfa' => 'account',
            'storage' => 'storage',
            default => 'all',
        };
    }
}
