<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/** Presentación provisional mientras los estudiantes solo tengan un campo `name`. */
final class StudentRosterName
{
    public static function display(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) < 2) return trim($name);

        // En los registros actuales los apellidos ocupan las dos últimas palabras.
        $surnameCount = count($parts) >= 3 ? 2 : 1;
        $surnames = array_slice($parts, -$surnameCount);
        $given = array_slice($parts, 0, -$surnameCount);

        return implode(' ', $surnames).' '.implode(' ', $given);
    }

    public static function sortKey(string $name): string
    {
        return Str::lower(Str::ascii(self::display($name)));
    }
}
