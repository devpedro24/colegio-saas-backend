<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Labels only. Passing, recovery and promotion continue to use the existing SIEE engine. */
final class BulletinPerformance
{
    public static function validate(array $rows, string $min, string $max): array
    {
        if ($rows === []) {
            return [];
        }
        $rows = Validator::make(['desempenos' => $rows], [
            'desempenos' => ['array', 'min:2', 'max:8'],
            'desempenos.*' => ['array:nombre,desde,hasta'],
            'desempenos.*.nombre' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'desempenos.*.desde' => ['required', 'numeric', 'decimal:0,1'],
            'desempenos.*.hasta' => ['required', 'numeric', 'decimal:0,1'],
        ])->validate()['desempenos'];
        usort($rows, fn ($a, $b) => BigDecimal::of((string) $a['desde'])->compareTo((string) $b['desde']));
        $next = BigDecimal::of($min);
        foreach ($rows as &$row) {
            $from = BigDecimal::of((string) $row['desde']);
            $to = BigDecimal::of((string) $row['hasta']);
            if (! $from->isEqualTo($next) || $to->isLessThan($from) || $to->isGreaterThan($max) || trim($row['nombre']) === '') {
                throw ValidationException::withMessages(['desempenos' => __('Los rangos deben cubrir la escala completa, sin huecos ni superposiciones, con un decimal.')]);
            }
            $row = ['nombre' => trim($row['nombre']), 'desde' => (string) $from->toScale(1), 'hasta' => (string) $to->toScale(1)];
            $next = $to->plus('0.1');
        }
        unset($row);
        if (! $next->minus('0.1')->isEqualTo($max)) {
            throw ValidationException::withMessages(['desempenos' => __('Los rangos deben cubrir la escala completa, sin huecos ni superposiciones, con un decimal.')]);
        }

        return $rows;
    }

    public static function label(?string $display, array $ranges): string
    {
        if ($display === null) {
            return '';
        }
        $value = BigDecimal::of($display);
        foreach ($ranges as $range) {
            if ($value->isGreaterThanOrEqualTo((string) $range['desde']) && $value->isLessThanOrEqualTo((string) $range['hasta'])) {
                return $range['nombre'];
            }
        }

        return '';
    }

    /** Presentation only: keep the stored labels and approval rules unchanged. */
    public static function legend(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => BigDecimal::of((string) $b['desde'])->compareTo((string) $a['desde']));

        return array_map(function (array $range): string {
            $name = $range['nombre'];
            $prefix = __('Desempeño');
            if (! preg_match('/^(?:desempeño|performance)(?:\s|$)/iu', $name)) {
                $name = $prefix.' '.$name;
            }

            return BigDecimal::of((string) $range['desde'])->toScale(1).' – '.BigDecimal::of((string) $range['hasta'])->toScale(1).': '.$name;
        }, $ranges);
    }
}
