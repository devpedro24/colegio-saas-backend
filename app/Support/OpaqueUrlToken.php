<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/** Stable, tenant-scoped public selectors. Database IDs remain private to API calls. */
final class OpaqueUrlToken
{
    public static function for(string $resource, int|string $id): string
    {
        $tenantKey = tenancy()->tenant?->getTenantKey();
        if ($tenantKey === null) {
            throw new LogicException('No se puede generar un selector público sin colegio activo.');
        }

        // 24 base64url characters retain 144 HMAC bits, with no numeric ID in the URL.
        $message = json_encode([(string) $tenantKey, $resource, (string) $id], JSON_THROW_ON_ERROR);
        $digest = hash_hmac('sha256', $message, (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
    }
}
