<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Cache compartida y acotada por colegio, identidad, permisos, grupos y revisión. */
final class EventAgendaCache
{
    public function remember(User $user, string $view, array $filters, Closure $compute): array
    {
        $tenantKey = (string) tenant()->getKey();
        $permissions = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        $groups = app(EventAccess::class)->groupIds($user);
        sort($groups);
        try {
            $revision = Cache::get(self::revisionKey($tenantKey), 'initial');
        } catch (\Throwable $error) {
            Log::warning('Agenda: caché no disponible', ['error' => $error->getMessage()]);
            return $compute();
        }
        $key = 'event-agenda:data:'.hash('sha256', json_encode([
            $tenantKey, (string) $user->getKey(), $user->role, $user->status,
            $permissions, $groups, $view, $filters, $revision,
        ], JSON_THROW_ON_ERROR));

        try {
            $cached = Cache::get($key);
            if (is_array($cached)) return $cached;
        } catch (\Throwable $error) {
            Log::warning('Agenda: lectura de caché fallida', ['error' => $error->getMessage()]);
        }
        $value = $compute();
        try {
            Cache::put($key, $value, now()->addMinutes(10));
        } catch (\Throwable $error) {
            Log::warning('Agenda: escritura de caché fallida', ['error' => $error->getMessage()]);
        }

        return $value;
    }

    /** El siguiente GET usa otra clave; las entradas antiguas caducan solas. */
    public function invalidate(string $tenantKey): void
    {
        try {
            Cache::forever(self::revisionKey($tenantKey), bin2hex(random_bytes(12)));
        } catch (\Throwable $error) {
            Log::warning('Agenda: invalidación de caché fallida', ['error' => $error->getMessage()]);
        }
    }

    private static function revisionKey(string $tenantKey): string
    {
        return 'event-agenda:revision:'.hash('sha256', $tenantKey);
    }
}
