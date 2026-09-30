<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Stable, tenant-scoped public selectors. Database IDs remain private to API calls. */
final class OpaqueUrlToken
{
    public static function valid(mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{24}\z/', $token) === 1;
    }

    /** Search only a caller-scoped tenant query; a selector does not grant access. */
    public static function find(string $resource, mixed $token, Builder $query): ?Model
    {
        if (! tenancy()->initialized || ! self::valid($token)) {
            return null;
        }

        $id = $query->getModel()->getConnection()->table(AcademicTokenIndex::TABLE)
            ->where('resource', $resource)->where('token', $token)->value('record_id');

        return $id !== null && hash_equals(self::for($resource, $id), $token)
            ? $query->whereKey($id)->first() : null;
    }

    /** Resolve a bounded batch, then reapply the caller's original scope. */
    public static function ids(string $resource, array $tokens, Builder $query): array
    {
        if (! tenancy()->initialized || $tokens === []) {
            return [];
        }
        $rows = $query->getModel()->getConnection()->table(AcademicTokenIndex::TABLE)
            ->where('resource', $resource)->whereIn('token', $tokens)->pluck('record_id', 'token');
        $allowed = $query->whereKey($rows->values()->all())->pluck($query->getModel()->getQualifiedKeyName())->flip();

        return $rows->filter(fn ($id, $token) => isset($allowed[$id])
            && hash_equals(self::for($resource, $id), $token))->all();
    }

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
