<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class RedactsSecrets
{
    public static function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|contrasena|contraseña|secret|token|authorization|cookie|recovery_code|otp|api_key/i', (string) $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = self::clean($value);
            }
        }

        return $values;
    }
}
