<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Exact decimal text at the database/API boundary; never binary floating point. */
final class AcademicDecimal implements CastsAttributes
{
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) BigDecimal::of(str_replace(',', '.', trim((string) $value)))->strippedOfTrailingZeros();
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normalize($value);
    }
}
