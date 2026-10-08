<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Models\StoredFile;
use App\Models\Tenant;
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
        // Delivery bookkeeping and PIN updates must not refresh unrelated modules.
        if ($table === 'ingreso_notificaciones') {
            return;
        }
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
            'correo_configuracion' => 'school-mail',
            'school_mail_change_requests' => 'school-mail-requests',
            'eventos' => 'events',
            'aulas', 'aula_secciones' => 'aula',
            'aula_recursos' => 'aula-grade',
            'aula_adjuntos', 'aula_entregas', 'aula_preguntas', 'aula_pregunta_medios' => 'aula-content',
            'aula_intentos', 'aula_incidentes', 'aula_respuesta_medios' => 'aula-attempt',
            'aula_vistas_recursos' => 'aula-progress',
            'ingreso_campanas', 'ingreso_solicitudes', 'ingreso_documentos' => 'enrollment-intake',
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
            // Classroom originals (including student submissions) only change
            // classroom content; they do not invalidate every storage consumer.
            if (str_contains('/'.ltrim((string) $model->path, '/'), '/aula/')) {
                $resource = 'aula-content';
            }
            if (str_contains('/'.ltrim((string) $model->path, '/'), '/matriculas/')) {
                $resource = 'enrollment-intake';
            }
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
