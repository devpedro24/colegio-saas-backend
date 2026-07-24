<?php

declare(strict_types=1);

namespace App\Models\Rbac;

/**
 * Rol del catalogo central (editable por el superadmin).
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property bool $is_system
 * @property int $sort_order
 */
class RbacRole extends CentralModel
{
    protected $table = 'rbac_roles';

    protected $fillable = [
        'key',
        'label',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];
}
