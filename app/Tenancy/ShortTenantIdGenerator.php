<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Genera un id CORTO (10 caracteres alfanumericos en minuscula) para el
 * tenant/colegio, en lugar del UUID de 36 caracteres.
 *
 * El id es la clave interna inmutable (RN-MT): se usa en la PK de `tenants`,
 * en los nombres de BD, en sufijos de storage/cache y en tokens. Por eso se
 * mantiene legible y URL-safe (solo [a-z0-9]); la unicidad la garantiza la PK.
 */
class ShortTenantIdGenerator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        return Str::lower(Str::random(10));
    }
}