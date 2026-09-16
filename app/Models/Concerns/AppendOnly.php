<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/** Bloquea mutaciones Eloquent sobre registros que son evidencia. */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Los registros de auditoria son append-only y no pueden modificarse.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Los registros de auditoria son append-only y no pueden eliminarse.');
        });
    }
}
