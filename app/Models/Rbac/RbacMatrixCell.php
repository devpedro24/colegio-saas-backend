<?php

declare(strict_types=1);

namespace App\Models\Rbac;

/**
 * Celda de la matriz global (central): clasificacion de un (rol, permiso).
 * Solo existen filas para 'structural' o 'configurable'; ausencia = 'denied'.
 *
 * @property int $id
 * @property string $role_key
 * @property string $permission_key
 * @property string $type
 * @property ?string $level
 * @property bool $default_granted
 */
class RbacMatrixCell extends CentralModel
{
    protected $table = 'rbac_matrix';

    protected $fillable = [
        'role_key',
        'permission_key',
        'type',
        'level',
        'default_granted',
    ];

    protected $casts = [
        'default_granted' => 'boolean',
    ];
}
