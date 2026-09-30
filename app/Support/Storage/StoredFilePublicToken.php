<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\StoredFile;

/** Indexed, tenant-scoped selector for signed download URLs and API responses. */
final class StoredFilePublicToken
{
    public static function for(StoredFile $file): string
    {
        $token = $file->public_token;
        if (! is_string($token) || preg_match('/\A[A-Za-z0-9_-]{24}\z/', $token) !== 1) {
            throw new \LogicException('El archivo no tiene selector público válido.');
        }

        return $token;
    }

    public static function findForTenant(string $tenantId, string $token): ?StoredFile
    {
        if (preg_match('/\A[A-Za-z0-9_-]{24}\z/', $token) !== 1) {
            return null;
        }

        return StoredFile::query()->where('tenant_id', $tenantId)
            ->where('public_token', $token)->first();
    }
}
