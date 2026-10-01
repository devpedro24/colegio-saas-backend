<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Models\Tenant;
use App\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;

/** Also covers domain writes from services, scheduled commands and queued jobs. */
final class ModelChanges
{
    public function __invoke(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || ! str_starts_with($model::class, 'App\\Models\\')) {
            return;
        }
        $table = $model->getTable();
        if (in_array($table, ['audit_logs', 'platform_audit_logs'], true)
            && ($model->recurso === 'http' || in_array($model->accion, ['READ', 'REQUEST', 'REQUEST_DENIED'], true))) {
            return; // Refreshing a view must not generate another refresh indefinitely.
        }
        $changes = app(RealtimeChanges::class);
        $connection = $model->getConnection();
        $tenantId = $connection->getName() === 'tenant' ? tenant()?->getKey() : null;
        $resource = match ($table) {
            'anos_lectivos', 'periodos', 'preinformes' => 'academic',
            'sedes', 'jornadas', 'niveles', 'grados', 'grupos', 'bloques_horarios', 'espacios_fisicos' => 'structure',
            'areas', 'materias' => 'curriculum',
            'asignaciones_docentes', 'sesiones_horario' => 'schedule',
            'matriculas', 'componentes_evaluacion', 'actividades_evaluacion', 'calificaciones' => 'evaluation',
            'escalas_valorativas', 'metodos_aprobacion', 'modelos_pedagogicos' => 'academic-config',
            'datos_institucionales' => 'institution',
            'eventos' => 'events',
            'users' => 'users',
            'plans' => 'plans',
            'tenants', 'impersonations' => 'schools',
            'rbac_roles', 'rbac_permissions', 'rbac_matrix' => 'rbac',
            'audit_logs', 'platform_audit_logs' => 'audit',
            'stored_files' => 'storage',
            default => 'all',
        };
        if ($model instanceof StoredFile) {
            $tenantId = (string) $model->tenant_id;
        }
        $changes->record($tenantId, $resource, $connection);
        if ($tenantId !== null && $resource === 'users' && tenant()?->parent_id) {
            $changes->record((string) tenant()->parent_id, 'users', $connection);
        }
        if ($model instanceof Tenant) {
            $changes->record((string) $model->getKey(), 'access', $connection);
        }
        if ($tenantId !== null && in_array($resource, ['structure', 'users', 'audit'], true)) {
            // Platform lists aggregate campuses/users/audit from the school databases.
            $changes->record(null, $resource === 'audit' ? 'audit' : 'schools', $connection);
        }
    }
}
