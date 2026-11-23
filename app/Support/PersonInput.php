<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

final class PersonInput
{
    /** Letters, accents and the separators used in personal names and relationships. */
    public static function name(bool $partial = false): Closure
    {
        $pattern = $partial
            ? "/\\A\\p{L}[\\p{L}\\p{M} '\\x{2019}\\x{02BC}-]*\\z/u"
            : "/\\A\\p{L}[\\p{L}\\p{M}]*(?:[ '\\x{2019}\\x{02BC}-]+\\p{L}[\\p{L}\\p{M}]*)*\\z/u";

        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            if (! is_string($value) || ! preg_match($pattern, $value)) {
                $fail(__('intake.invalidName'));
            }
        };
    }
}
