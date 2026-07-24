<?php

declare(strict_types=1);

namespace App\Models\Rbac;

/**
 * Permiso del catalogo central (editable por el superadmin).
 *
 * @property int $id
 * @property string $key
 * @property string $module
 * @property string $action
 * @property ?string $feature_key
 * @property ?string $description
 * @property bool $is_system
 * @property int $sort_order
 */
class RbacPermission extends CentralModel
{
    protected $table = 'rbac_permissions';

    protected $fillable = [
        'key',
        'module',
        'action',
        'feature_key',
        'description',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];
}
