<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Nombre de la base de datos PostgreSQL de un colegio.
 *
 * Patron: `tenant_<nombre del colegio slugificado>_<id corto>`.
 * Ejemplo: Tenant "Colegio San Jose" con id `k7x2m9p4qr` ->
 *          `tenant_colegio_san_jose_k7x2m9p4qr`.
 *
 * Los tenants existentes creados antes del id corto conservan un UUID como
 * clave interna; para no contaminar el nombre de BD con 36 caracteres, el
 * sufijo se deriva a 10 caracteres alfanumericos (hex del UUID sin guiones).
 *
 * Postgres limita los identificadores a 63 bytes: si el nombre lo excede se
 * recorta la parte legible conservando siempre el sufijo con el id.
 */
class TenantDatabaseName
{
    /**
     * Sufijo corto (10 chars [a-z0-9]) para el nombre de BD.
     * - Ids ya cortos (nuevos tenants) se usan tal cual.
     * - UUIDs (tenants legacy) se derivan a sus primeros 10 hex sin guiones:
     *   determinista (mismo nombre en cada corrida) y sin caracteres raros.
     */
    private static function shortKey(?string $id): string
    {
        if ($id === null || $id === '') {
            return Str::lower(Str::random(10));
        }

        $id = strtolower($id);
        if (strlen($id) <= 10) {
            return $id;
        }

        $hex = preg_replace('/[^a-z0-9]/', '', $id);

        return substr($hex, 0, 10);
    }

    /**
     * @param  Tenant|null  $tenant
     */
    public static function for(?Tenant $tenant): string
    {
        $name = $tenant?->name ?? 'colegio';
        $id = self::shortKey($tenant?->getKey());

        $slug = Str::slug($name, '_', 'es');
        if ($slug === '') {
            $slug = 'colegio';
        }

        $base = 'tenant_'.$slug.'_'.$id;

        // PostgreSQL: maximo 63 bytes (ASCII despues del slug, seguro con strlen).
        if (strlen($base) > 63) {
            $room = 63 - strlen('tenant__') - strlen($id);
            $base = 'tenant_'.substr($slug, 0, max(1, $room)).'_'.$id;
        }

        return strtolower($base);
    }
}