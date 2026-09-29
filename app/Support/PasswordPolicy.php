<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(8)->max(128)->mixedCase()->numbers()->symbols();
    }

    public static function temporary(int $length = 14): string
    {
        if ($length < 8 || $length > 128) {
            throw new InvalidArgumentException('La longitud debe estar entre 8 y 128 caracteres.');
        }

        do {
            $password = Str::password($length);
        } while (! preg_match('/[a-z]/', $password)
            || ! preg_match('/[A-Z]/', $password)
            || ! preg_match('/[0-9]/', $password)
            || ! preg_match('/[[:punct:]]/', $password));

        return $password;
    }
}
