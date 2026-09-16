<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de auditoria del COLEGIO (BD del tenant) — append-only (RN-LA-001..006).
 *
 * Vive en la conexion por defecto, que stancl apunta a la BD del colegio activo;
 * por eso el colegio es IMPLICITO y no hay columna `tenant_id`.
 *
 * Modelo inmutable: `const UPDATED_AT = null` desactiva `updated_at`, de modo que
 * Eloquent solo maneja `created_at`. No expongas ni implementes update/delete sobre
 * estas filas: son evidencia. Escribe siempre via App\Support\Audit\AuditLogger.
 *
 * @property int $id
 * @property ?int $actor_id
 * @property ?string $actor_email
 * @property ?string $actor_rol
 * @property ?string $impersonated_by
 * @property string $accion
 * @property string $recurso
 * @property ?string $recurso_id
 * @property ?array<string,mixed> $valor_previo
 * @property ?array<string,mixed> $valor_nuevo
 * @property ?string $motivo
 * @property ?string $ip
 * @property ?string $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use AppendOnly;

    protected $table = 'audit_logs';

    /** Append-only: sin columna `updated_at`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_email',
        'actor_rol',
        'impersonated_by',
        'accion',
        'recurso',
        'recurso_id',
        'valor_previo',
        'valor_nuevo',
        'motivo',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'valor_previo' => 'array',
        'valor_nuevo' => 'array',
    ];
}
