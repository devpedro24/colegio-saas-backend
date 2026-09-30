<?php

declare(strict_types=1);

namespace App\Support\Audit;

/** Removes internal database selectors from audit records sent to the browser. */
final class AuditPublicPresenter
{
    /** @param array<string, mixed> $entry @return array<string, mixed> */
    public static function entry(array $entry, string $scope): array
    {
        return self::sanitize($entry, $scope);
    }

    /** @param array<mixed> $entry @return array<mixed> */
    private static function sanitize(array $entry, string $scope): array
    {
        foreach ($entry as $key => $value) {
            if (! is_string($key)) {
                if (is_array($value)) {
                    $entry[$key] = self::sanitize($value, $scope);
                }
                continue;
            }

            $normalized = strtolower($key);
            if (preg_match('/(?:password|secret|token|api_key)/', $normalized)) {
                $entry[$key] = '[REDACTED]';
            } elseif (self::isIdKey($key)) {
                $entry[$key] = self::selector($scope, $normalized, $value);
            } elseif (is_array($value)) {
                $entry[$key] = self::sanitize($value, $scope);
            }
        }

        return $entry;
    }

    private static function isIdKey(string $key): bool
    {
        $lower = strtolower($key);

        return $lower === 'id' || $lower === 'ids' || str_ends_with($lower, '_id')
            || str_ends_with($lower, '_ids') || preg_match('/[a-z](?:Id|Ids)$/', $key) === 1;
    }

    private static function selector(string $scope, string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn ($item) => self::selector($scope, $key, $item), $value);
        }
        if (! is_scalar($value)) {
            return '[REDACTED]';
        }

        $message = json_encode(['audit', $scope, $key, (string) $value], JSON_THROW_ON_ERROR);
        $digest = hash_hmac('sha256', $message, (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
    }
}
