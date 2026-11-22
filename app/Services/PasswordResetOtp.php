<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Short-lived challenges in shared, persistent cache; no codes in API responses or audit. */
final class PasswordResetOtp
{
    public const LIFETIME = 600;
    public const RESEND_AFTER = 60;
    public const MAX_ATTEMPTS = 5;

    private function cache()
    {
        return Cache::store(config('auth.password_reset_otp_store', 'database'));
    }

    private function key(string $email): string
    {
        return 'password-reset-otp:'.hash_hmac('sha256', (tenant()?->getKey() ?? 'platform').':'.mb_strtolower($email), config('app.key'));
    }

    public function throttle(string $email): void
    {
        $limiter = new RateLimiter($this->cache());
        $key = $this->key($email).':request';
        $hour = $key.':hour';
        if ($limiter->tooManyAttempts($key, 1) || $limiter->tooManyAttempts($hour, 5)) {
            $wait = $limiter->tooManyAttempts($hour, 5) ? $limiter->availableIn($hour) : $limiter->availableIn($key);
            throw new TooManyRequestsHttpException($wait, 'Espera antes de solicitar otro correo de recuperación.');
        }
        $limiter->hit($key, self::RESEND_AFTER);
        $limiter->hit($hour, 3600);
    }

    public function lock(string $email, callable $callback): mixed
    {
        try { return $this->cache()->lock($this->key($email).':lock', 30)->block(5, $callback); }
        catch (LockTimeoutException) { throw new TooManyRequestsHttpException(5, 'Hay una recuperación en curso. Espera e intenta de nuevo.'); }
    }

    private function secret(string $email, string $challenge, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $this->key($email).':'.$challenge.':'.$code, config('app.key'));
    }

    /** Caller holds the account lock. A new code invalidates the previous challenge. */
    public function issue(?User $user, string $email): array
    {
        $challenge = Str::random(24);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Equal hashing work for an unknown or disabled account.
        $hash = Hash::make($this->secret($email, $challenge, $code));
        if ($user) {
            $saved = $this->cache()->put($this->key($email), [
                'challenge' => hash('sha256', $challenge), 'code' => $hash,
                'credential' => hash('sha256', $user->password),
                'attempts' => 0, 'expires' => now()->timestamp + self::LIFETIME,
            ], self::LIFETIME);
            if (! $saved) throw new ServiceUnavailableHttpException(null, 'No se pudo preparar el código. Intenta nuevamente.');
        }
        return ['challenge' => $challenge, 'code' => $code];
    }

    /** Caller holds the account and user-row locks. Failed attempts persist. */
    public function verify(?User $user, string $email, string $challenge, #[\SensitiveParameter] string $code): bool
    {
        $state = $this->cache()->get($this->key($email));
        if (! $user || ! is_array($state) || $state['expires'] <= now()->timestamp
            || ! hash_equals($state['challenge'], hash('sha256', $challenge))
            || ! hash_equals($state['credential'], hash('sha256', $user->password))) {
            return false;
        }
        if ($state['attempts'] >= self::MAX_ATTEMPTS) return false;
        $state['attempts']++;
        $valid = Hash::check($this->secret($email, $challenge, $code), $state['code']);
        if (! $valid) {
            $this->cache()->put($this->key($email), $state, max(1, $state['expires'] - now()->timestamp));
        }
        return $valid;
    }

    public function forget(string $email): void
    {
        $this->cache()->forget($this->key($email));
    }
}
