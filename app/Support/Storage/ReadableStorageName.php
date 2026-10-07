<?php

declare(strict_types=1);

namespace App\Support\Storage;

/** Human-readable, cross-platform path components without database IDs. */
final class ReadableStorageName
{
    public static function segment(string $label, string $fallback = 'Archivo', int $limit = 80): string
    {
        $name = preg_replace('/[^\p{L}\p{N}_.\[\]()&+-]+/u', '_', trim($label)) ?? '';
        $name = preg_replace('/_+/u', '_', $name) ?? '';
        $name = trim(mb_substr($name, 0, $limit), '._-');

        if ($name === '') $name = $fallback;
        if (preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/iD', $name)) $name = '_'.$name;

        return $name;
    }
}
