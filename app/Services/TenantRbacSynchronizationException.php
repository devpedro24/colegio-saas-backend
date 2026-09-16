<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class TenantRbacSynchronizationException extends RuntimeException
{
    /** @param array{attempted:int,synced:list<string>,failed:array<string,string>,policy:string} $result */
    public function __construct(public readonly array $result)
    {
        parent::__construct(
            'Fallo la sincronizacion RBAC de '.count($result['failed']).' tenant(s): '
            .implode(', ', array_keys($result['failed'])),
        );
    }
}
