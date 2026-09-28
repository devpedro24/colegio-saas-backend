<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new \LogicException('La auditoría no se puede modificar.'));
        static::deleting(fn () => throw new \LogicException('La auditoría no se puede eliminar.'));
    }
}
