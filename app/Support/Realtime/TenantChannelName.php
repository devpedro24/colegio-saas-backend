<?php

declare(strict_types=1);

namespace App\Support\Realtime;

/** Public channel selector. The current tenant and user still require private-channel authorization. */
final class TenantChannelName
{
    public static function tokenForId(string $id): string
    {
        $digest = hash_hmac('sha256', json_encode(['tenant-channel', $id], JSON_THROW_ON_ERROR),
            (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
    }
}
