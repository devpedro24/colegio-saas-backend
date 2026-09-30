<?php

declare(strict_types=1);

namespace App\Support\Mfa;

use App\Models\User;

final class MfaVerifier
{
    public function __construct(private TotpService $totp) {}

    public function verify(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/D', $code)) {
            return $user->getTwoFactorSecret() && $this->totp->verify($user->getTwoFactorSecret(), $code);
        }
        $normalized = strtolower(str_replace('-', '', trim($code)));
        if (! preg_match('/^[a-f0-9]{20}$/D', $normalized)) {
            return false;
        }

        // El bloqueo y la eliminación hacen que cada código de recuperación se use una sola vez.
        return $user->getConnection()->transaction(function () use ($user, $normalized) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hashes = json_decode($locked->two_factor_recovery_codes ?? '[]', true) ?: [];
            $index = array_search(hash('sha256', $normalized), $hashes, true);
            if ($index === false) {
                return false;
            }
            unset($hashes[$index]);
            $locked->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

            return true;
        });
    }
}
