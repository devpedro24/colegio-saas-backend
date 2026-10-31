<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Private server cache of content only. Every caller must authorize the current group first. */
final class BulletinObservationCache
{
    public function rows(int $groupId, int $periodId, array $enrollmentIds): array
    {
        sort($enrollmentIds);
        $compute = fn () => DB::table('observaciones_boletin')->whereIn('matricula_id', $enrollmentIds)
            ->where('contexto', 'periodo:'.$periodId)->get()->map(fn ($row) => (array) $row)->all();
        try {
            $revision = Cache::get($this->revisionKey($groupId), 'initial');
            // Membership is part of the key: moving a student cannot reuse their old group's data.
            $key = 'bulletin-observations:rows:'.hash('sha256', json_encode([
                (string) tenant()->getKey(), $groupId, $periodId, $enrollmentIds, $revision,
            ], JSON_THROW_ON_ERROR));
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $error) {
            Log::warning('Bulletin observation cache unavailable', ['error' => $error->getMessage()]);

            return $compute();
        }
        $rows = $compute();
        try {
            Cache::put($key, $rows, now()->addMinutes(5));
        } catch (\Throwable $error) {
            Log::warning('Bulletin observation cache write failed', ['error' => $error->getMessage()]);
        }

        return $rows;
    }

    public function invalidate(int $groupId): void
    {
        try {
            Cache::put($this->revisionKey($groupId), bin2hex(random_bytes(12)), now()->addMinutes(10));
        } catch (\Throwable $error) {
            Log::warning('Bulletin observation cache invalidation failed', ['error' => $error->getMessage()]);
        }
    }

    private function revisionKey(int $groupId): string
    {
        return 'bulletin-observations:revision:'.hash('sha256', (string) tenant()->getKey().':'.$groupId);
    }
}
