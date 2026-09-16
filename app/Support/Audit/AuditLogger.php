<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Jobs\PersistAuditLog;
use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Impersonation\ImpersonationAccess;
use Illuminate\Support\Facades\Log;

/**
 * Escritor unico del log de auditoria append-only (RN-LA-001..006, RG-002).
 *
 * Dos destinos segun el alcance de la accion:
 *   - platform(): tabla `platform_audit_logs` en la BD CENTRAL (superadmin, planes,
 *     RBAC central, ciclo de vida de colegios...). Lleva `tenant_id` explicito.
 *   - tenant():   tabla `audit_logs` en la BD del colegio activo (colegio implicito).
 *
 * Contrato de robustez: la auditoria NUNCA debe tumbar la operacion auditada.
 * Si el INSERT directo falla, el evento se ENCOLA como fallback (PersistAuditLog)
 * con reintentos y backoff, y ademas se deja un warning. La evidencia no se
 * pierde en silencio. El log de auditoria es evidencia inmutable: aqui solo se
 * INSERTA, jamas se actualiza ni se borra.
 */
final class AuditLogger
{
    /**
     * Registra un evento de auditoria de PLATAFORMA (BD central).
     *
     * @param  object|null  $actor  Usuario/superadmin que ejecuta la accion (o null si es el sistema).
     * @param  string  $accion  CREATE|UPDATE|DELETE|READ|LOGIN|SUSPEND|...
     * @param  string  $recurso  Nombre del recurso afectado (p.ej. tenant, plan).
     * @param  string|null  $recursoId  Identificador del recurso (string: soporta uuid/int).
     * @param  array<string,mixed>|null  $prev  Estado previo del recurso.
     * @param  array<string,mixed>|null  $new  Estado nuevo del recurso.
     * @param  string|null  $motivo  Justificacion de la accion.
     * @param  string|null  $tenantId  ID interno del colegio afectado (null si es global).
     */
    public static function platform(
        ?object $actor,
        string $accion,
        string $recurso,
        ?string $recursoId = null,
        ?array $prev = null,
        ?array $new = null,
        ?string $motivo = null,
        ?string $tenantId = null,
    ): void {
        try {
            [$actorId, $actorEmail, $actorRol] = self::resolveActor($actor);

            PlatformAuditLog::create([
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_rol' => $actorRol,
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'tenant_id' => $tenantId,
                'valor_previo' => $prev,
                'valor_nuevo' => $new,
                'motivo' => $motivo,
                'ip' => self::currentIp(),
                'user_agent' => self::currentUserAgent(),
            ]);
        } catch (\Throwable $e) {
            // La auditoria no debe romper la operacion auditada (RG-002): se encola
            // el evento para reintentarlo y se alerta en los logs del framework.
            self::dispatchFallback('platform', $actor, null, [
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'tenant_id' => $tenantId,
                'valor_previo' => $prev,
                'valor_nuevo' => $new,
                'motivo' => $motivo,
                'error' => $e->getMessage(),
            ]);

            Log::warning('AuditLogger::platform fallo al registrar evento', [
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra un evento de auditoria en el COLEGIO activo (BD del tenant).
     *
     * `impersonated_by` (doble identificador, RN-LA-002): cuando el superadmin
     * actua DENTRO del colegio suplantando al rector, se conserva su email. Se
     * puede pasar EXPLICITAMENTE (lo hace el controlador de suplantacion en los
     * eventos IMPERSONATE_*) o dejarlo en null: en ese caso se AUTORESUELVE si la
     * peticion viene autenticada con un token Sanctum `impersonation:<sessionId>`
     * (usuario sombra), consultando ESA sesion en la tabla central
     * `impersonations` del tenant actual. Asi TODA accion del colegio hecha bajo
     * suplantacion queda marcada sin tocar cada controlador. En operacion normal
     * (token 'web') queda null y no se hace ninguna consulta extra.
     *
     * @param  object|null  $actor  Usuario del tenant que ejecuta la accion (o null si es el sistema).
     * @param  string  $accion  CREATE|UPDATE|DELETE|READ|LOGIN|SUSPEND|...
     * @param  string  $recurso  Nombre del recurso afectado (p.ej. estudiante, nota).
     * @param  string|null  $recursoId  Identificador del recurso (string: soporta uuid/int).
     * @param  array<string,mixed>|null  $prev  Estado previo del recurso.
     * @param  array<string,mixed>|null  $new  Estado nuevo del recurso.
     * @param  string|null  $motivo  Justificacion de la accion.
     * @param  string|null  $impersonatedBy  Email del superadmin detras (o null = autoresolver).
     */
    public static function tenant(
        ?object $actor,
        string $accion,
        string $recurso,
        ?string $recursoId = null,
        ?array $prev = null,
        ?array $new = null,
        ?string $motivo = null,
        ?string $impersonatedBy = null,
    ): void {
        try {
            [$actorId, $actorEmail, $actorRol] = self::resolveActor($actor);

            AuditLog::create([
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_rol' => $actorRol,
                'impersonated_by' => $impersonatedBy ?? self::currentImpersonatedBy(),
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'valor_previo' => $prev,
                'valor_nuevo' => $new,
                'motivo' => $motivo,
                'ip' => self::currentIp(),
                'user_agent' => self::currentUserAgent(),
            ]);
        } catch (\Throwable $e) {
            // La auditoria no debe romper la operacion auditada (RG-002): se encola
            // el evento para reintentarlo y se alerta en los logs del framework.
            self::dispatchFallback('tenant', $actor, (string) (tenant()?->getKey() ?? ''), [
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'valor_previo' => $prev,
                'valor_nuevo' => $new,
                'motivo' => $motivo,
                'impersonated_by' => $impersonatedBy ?? self::currentImpersonatedBy(),
                'error' => $e->getMessage(),
            ]);

            Log::warning('AuditLogger::tenant fallo al registrar evento', [
                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Encola el evento como FALLBACK cuando el insert directo fallo (RG-002).
     * Payload sin datos sensibles adicionales: la desnormalizacion del actor ya
     * viene resuelta en $attributes (id/email/rol), no se serializan modelos.
     */
    private static function dispatchFallback(string $target, ?object $actor, ?string $tenantId, array $context): void
    {
        try {
            [$actorId, $actorEmail, $actorRol] = self::resolveActor($actor);

            $attributes = [
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_rol' => $actorRol,
                'accion' => $context['accion'] ?? null,
                'recurso' => $context['recurso'] ?? null,
                'recurso_id' => $context['recurso_id'] ?? null,
                'valor_previo' => $context['valor_previo'] ?? null,
                'valor_nuevo' => $context['valor_nuevo'] ?? null,
                'motivo' => $context['motivo'] ?? null,
                'ip' => self::currentIp(),
                'user_agent' => self::currentUserAgent(),
            ];

            if ($target === 'platform') {
                $attributes['tenant_id'] = $context['tenant_id'] ?? $tenantId;
            } else {
                $attributes['impersonated_by'] = $context['impersonated_by'] ?? null;
            }

            PersistAuditLog::dispatch([
                'target' => $target,
                'tenant_id' => $tenantId,
                'attributes' => $attributes,
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditLogger: no se pudo encolar el fallback de auditoria', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Desnormaliza id/email/rol del actor de forma defensiva (sobrevive borrados).
     *
     * @return array{0:int|null,1:string|null,2:string|null}
     */
    private static function resolveActor(?object $actor): array
    {
        if ($actor === null) {
            return [null, null, null];
        }

        $actorId = null;
        if (method_exists($actor, 'getKey')) {
            $key = $actor->getKey();
            $actorId = is_numeric($key) ? (int) $key : null;
        } elseif (isset($actor->id) && is_numeric($actor->id)) {
            $actorId = (int) $actor->id;
        }

        $actorEmail = isset($actor->email) ? (string) $actor->email : null;

        // Preferimos la columna `role`; si no, tomamos el primer rol de spatie.
        $actorRol = null;
        if (isset($actor->role) && $actor->role !== null && $actor->role !== '') {
            $actorRol = (string) $actor->role;
        } elseif (method_exists($actor, 'getRoleNames')) {
            $roles = $actor->getRoleNames();
            if ($roles !== null && method_exists($roles, 'first') && $roles->first() !== null) {
                $actorRol = (string) $roles->first();
            }
        }

        return [$actorId, $actorEmail, $actorRol];
    }

    /**
     * Resuelve el email del superadmin detras de una accion cuando la peticion
     * viene autenticada con el token de suplantacion (usuario sombra 'rector').
     *
     * Se apoya en el nombre/ability del token Sanctum para NO gravar las
     * operaciones normales (token 'web' -> corta antes de tocar la BD). Si hay
     * suplantacion, resuelve por sessionId exacto el email del superadmin en la
     * tabla central `impersonations` del colegio actual.
     */
    private static function currentImpersonatedBy(): ?string
    {
        try {
            $user = auth()->user();
            $session = $user instanceof User
                ? app(ImpersonationAccess::class)->sessionFor($user)
                : null;

            return $session?->superadmin_email;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function currentIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function currentUserAgent(): ?string
    {
        try {
            return request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }
}
