<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ContactNumbers;
use Closure;

final class InstitutionInput
{
    /** Institutional names may contain numbers and ordinary punctuation. */
    public static function name(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/\A(?=.*\p{L})[\p{L}\p{M}0-9 .,\'’"()&\/#º°:+-]+\z/u', $value)) {
                $fail(__('institution.invalidName'));
            }
        };
    }

    public static function text(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '' || preg_match('/[<>\x00-\x1F\x7F]/u', $value)) {
                $fail(__('institution.invalidText'));
            }
        };
    }

    public static function rules(): array
    {
        return [
            'nombre' => ['bail', 'required', 'string', 'max:255', self::name()],
            'nit' => ['bail', 'required', 'string', 'max:60', 'regex:/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9])?\z/D'],
            'resolucion_men' => ['bail', 'required', 'string', 'max:255', self::text()],
            'direccion' => ['bail', 'required', 'string', 'max:255', self::text()],
            'telefono' => ['bail', 'required', 'string', 'max:32'],
            'correo' => ['bail', 'required', 'string', 'max:255', 'email:rfc'],
        ];
    }

    public static function normalize(array $data): array
    {
        $fallback = ContactNumbers::countryForTimezone(tenant()->zonaHorariaInstitucional());
        $data['telefono'] = ContactNumbers::normalize($data['telefono'],
            ContactNumbers::countryForNumber($data['telefono'], $fallback), 'telefono');

        return $data;
    }
}
