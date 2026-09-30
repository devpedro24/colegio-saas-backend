<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/** Stable display selector; never accepted as proof of authorization. */
final class PublicIdentityToken
{
    public static function forUser(User $user): string
    {
        $scope = (string) (tenant()?->getTenantKey() ?? 'platform');
        $message = json_encode([$scope, 'user', (string) $user->getKey()], JSON_THROW_ON_ERROR);
        $digest = hash_hmac('sha256', $message, (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
    }
}
