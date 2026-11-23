<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** Rolling windows count attempted deliveries, including concurrent jobs across tenants. */
final class SchoolMailQuota
{
    private function key(string $sender): string
    {
        $sender = strtolower(trim($sender));
        if (preg_match('/^([^@]+)@(gmail|googlemail)\.com$/', $sender, $parts)) {
            $sender = str_replace('.', '', explode('+', $parts[1])[0]).'@gmail.com';
        }

        return hash('sha256', $sender);
    }

    public function reserve(string $sender, bool $bulk): void
    {
        $daily = (int) config('mail.school_per_day');
        if (preg_match('/@(gmail|googlemail)\.com$/i', trim($sender))) {
            $daily = min(500, $daily);
        }
        $key = $this->key($sender);
        $cache = Cache::store('school_mail');
        try {
            $cache->lock($key.':lock', 10)->block(2, function () use ($key, $bulk, $cache, $daily) {
                $now = now()->timestamp;
                $delay = (int) $cache->get($key.':pause') - $now;
                $times = array_values(array_filter($cache->get($key, []), fn ($t) => $t > $now - 86400));
                foreach ([60 => config('mail.school_per_minute'), 86400 => $daily] as $window => $limit) {
                    // Leave some capacity for admissions and PINs when classes publish together.
                    $limit = max(1, (int) $limit);
                    if ($bulk) {
                        $limit -= intdiv($limit, 10);
                    }
                    $index = count($times) - $limit;
                    if ($index >= 0) {
                        $delay = max($delay, $times[$index] + $window - $now);
                    }
                }
                if ($delay > 0) {
                    throw new SchoolMailDeferred($delay);
                }
                $times[] = $now;
                $cache->put($key, $times, 86401);
            });
        } catch (LockTimeoutException) {
            throw new SchoolMailDeferred(5);
        }
    }

    public function handleProviderLimit(string $sender, \Throwable $error): void
    {
        if (! $error instanceof TransportExceptionInterface) {
            return;
        }
        $message = $error->getMessage();
        if (preg_match('/(?:5\.4\.5|daily .*limit|daily .*quota)/i', $message)) {
            $delay = 86400;
        } elseif (preg_match('/(?:4\.7\.(?:0|28)|4\.5\.3|rate limit|sending rate)/i', $message)) {
            $delay = 900;
        } else {
            return;
        }
        $cache = Cache::store('school_mail');
        $key = $this->key($sender);
        $cache->lock($key.':lock', 10)->block(2, function () use ($cache, $key, $delay) {
            $until = max((int) $cache->get($key.':pause'), now()->timestamp + $delay);
            $cache->put($key.':pause', $until, $until - now()->timestamp + 1);
        });
        throw new SchoolMailDeferred($delay);
    }
}
