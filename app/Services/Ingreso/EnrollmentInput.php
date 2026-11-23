<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Support\PersonInput;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EnrollmentInput
{
    public const NAME_FIELDS = ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'madre', 'padre', 'responsable'];

    public static function name(bool $partial = false): Closure
    {
        return PersonInput::name($partial);
    }

    public static function identification(mixed $type, bool $partial = false): Closure
    {
        $numeric = in_array($type, ['CC', 'TI', 'RC'], true);
        $min = $partial ? 1 : 3;
        $pattern = '/\A'.($numeric ? '[0-9]{'.$min.',40}' : ($partial ? '[A-Za-z0-9][A-Za-z0-9-]{0,39}' : '[A-Za-z0-9][A-Za-z0-9-]{1,38}[A-Za-z0-9]')).'\z/D';

        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern, $numeric): void {
            if (! is_string($value) || ! preg_match($pattern, $value)) {
                $fail(__($numeric ? 'intake.numericIdentity' : 'intake.invalidIdentity'));
            }
        };
    }

    public static function relationship(bool $partial = false): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($partial): void {
            PersonInput::name($partial)($attribute, $value, $fail);
            if (! $partial && is_string($value) && Str::lower(trim($value)) === 'otro') {
                $fail(__('intake.specifyRelationship'));
            }
        };
    }

    public static function plain(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                $fail(__('intake.invalidText'));
            }
        };
    }

    /** Explicit field types take priority; only free-text labels are inferred. */
    public static function additionalType(array $field): string
    {
        if ($field['tipo'] !== 'texto') {
            return $field['tipo'];
        }
        $label = Str::lower(Str::ascii(preg_replace('/[_-]+/', ' ', $field['key'].' '.$field['nombre'])));
        if (preg_match('/\b(telefono|celular|movil)\b/', $label)) {
            return 'telefono';
        }
        if (preg_match('/\b(correo|email|e mail)\b/', $label)) {
            return 'email';
        }
        if (preg_match('/\b(pasaporte|extranjeria)\b/', $label)) {
            return 'identificacion';
        }
        if (preg_match('/\b(cedula|cc|ti|rc|nuip)\b/', $label)) {
            return 'identificacion_numerica';
        }
        if (preg_match('/\b(identificacion|identidad)\b/', $label)) {
            return 'identificacion';
        }
        if (preg_match('/\b(parentesco|relacion|vinculo)\b/', $label)) {
            return 'parentesco';
        }
        if (preg_match('/\b(nombres?|apellidos?)\b/', $label)
            && ! preg_match('/\b(colegio|institucion|empresa|programa|sede|curso|grado|barrio|archivo|documento)\b/', $label)) {
            return 'nombre';
        }

        return 'texto';
    }

    public static function additionalRules(array $field, bool $partial = false): array
    {
        $type = self::additionalType($field);

        return ['bail', $partial || ! $field['obligatorio'] ? 'nullable' : 'required', 'string', ...match ($type) {
            'nombre' => ['max:160', self::name($partial)],
            'parentesco' => ['max:100', self::relationship($partial)],
            'identificacion_numerica' => ['max:40', self::identification('CC', $partial)],
            'identificacion' => ['max:40', self::identification('OTRO', $partial)],
            'numero' => ['max:1000', 'regex:/\A[0-9]+\z/D'],
            'fecha' => ['max:10', 'date_format:Y-m-d'],
            'telefono' => ['max:16', 'regex:/\A\+[1-9][0-9]{0,14}\z/D'],
            'email' => $partial ? ['max:255', 'regex:/\A[^\s@<>\x00-\x1F\x7F]*@?[^\s@<>\x00-\x1F\x7F]*\z/u'] : ['max:255', 'email:rfc'],
            'seleccion' => ['max:1000', Rule::in($field['opciones'] ?? [])],
            default => ['max:1000', self::plain()],
        }];
    }
}
