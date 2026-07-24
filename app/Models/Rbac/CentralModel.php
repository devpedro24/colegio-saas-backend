<?php

declare(strict_types=1);

namespace App\Models\Rbac;

use Illuminate\Database\Eloquent\Model;

/**
 * Base para los modelos del catalogo RBAC (que vive en la BD CENTRAL).
 *
 * Fuerza la conexion central aunque el request este en contexto de un tenant
 * (stancl cambia la conexion 'default' a la del colegio; el catalogo es
 * metadato de plataforma y debe leerse siempre desde central).
 */
abstract class CentralModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
