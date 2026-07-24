<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan / membresia SaaS (BD central).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ?string $description
 * @property bool $is_active
 * @property ?string $price_monthly
 * @property ?string $price_annual
 * @property ?int $max_estudiantes
 * @property ?int $storage_gb
 * @property ?int $max_sedes
 * @property ?int $max_pasarelas
 * @property array<int,string> $features
 * @property int $sort_order
 */
class Plan extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
        'price_monthly',
        'price_annual',
        'max_estudiantes',
        'storage_gb',
        'max_sedes',
        'max_pasarelas',
        'features',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_annual' => 'decimal:2',
        'max_estudiantes' => 'integer',
        'storage_gb' => 'integer',
        'max_sedes' => 'integer',
        'max_pasarelas' => 'integer',
        'features' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Los planes viven en la BD CENTRAL. Forzar la conexion central permite
     * leerlos tambien desde el contexto de un tenant (para el gating del RBAC),
     * donde stancl ha cambiado la conexion 'default' a la del colegio.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
