<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Rbac\CentralModel;
use Illuminate\Support\Carbon;

/**
 * Sesion de SUPLANTACION del superadministrador sobre un colegio (BD CENTRAL).
 *
 * Extiende CentralModel para forzar la conexion central: se consulta tambien
 * desde el contexto de un tenant (al resolver `impersonated_by` en la auditoria
 * del colegio), donde stancl ha cambiado la conexion 'default' a la del colegio.
 *
 * @property int $id
 * @property string $session_id
 * @property int $superadmin_id
 * @property string $superadmin_email
 * @property string $tenant_id
 * @property ?string $motivo
 * @property ?string $ticket
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property ?Carbon $ended_at
 */
class Impersonation extends CentralModel
{
    protected $table = 'impersonations';

    protected $fillable = [
        'session_id',
        'superadmin_id',
        'superadmin_email',
        'tenant_id',
        'motivo',
        'ticket',
        'started_at',
        'expires_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
