<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Controlled blocks only: no HTML, remote assets or executable template expressions. */
final class BulletinTemplateDesign
{
    public const SECTIONS = ['student', 'areas', 'subjects', 'indicators', 'observations', 'attendance', 'signature'];

    public static function base(string $scope): array
    {
        return [
            'accent' => '#183C67', 'font' => 'sans', 'density' => 'comfortable',
            'heading' => $scope === 'final' ? 'Informe final de desempeño' : 'Informe de desempeño académico',
            'subheading' => 'Una mirada clara al progreso de cada estudiante',
            'sections' => array_map(fn (string $key): array => [
                'key' => $key, 'visible' => ! in_array($key, ['attendance', 'signature'], true),
            ], self::SECTIONS),
        ];
    }

    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'nombre' => ['required', 'string', 'min:3', 'max:120', 'not_regex:/[<>]/'],
            'ambito' => ['required', Rule::in(['periodo', 'final'])],
            'configuracion' => ['required', 'array:accent,font,density,heading,subheading,sections'],
            'configuracion.accent' => ['required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'configuracion.font' => ['required', Rule::in(['sans', 'serif', 'rounded'])],
            'configuracion.density' => ['required', Rule::in(['comfortable', 'compact'])],
            'configuracion.heading' => ['required', 'string', 'min:3', 'max:80', 'not_regex:/[<>]/'],
            'configuracion.subheading' => ['nullable', 'string', 'max:140', 'not_regex:/[<>]/'],
            'configuracion.sections' => ['required', 'array', 'size:7'],
            'configuracion.sections.*' => ['required', 'array:key,visible'],
            'configuracion.sections.*.key' => ['required', Rule::in(self::SECTIONS)],
            'configuracion.sections.*.visible' => ['required', 'boolean'],
        ])->validate();
        $sections = $data['configuracion']['sections'];
        $keys = array_column($sections, 'key');
        if (count(array_unique($keys)) !== count(self::SECTIONS)
            || array_diff(self::SECTIONS, $keys)
            || ! collect($sections)->firstWhere('key', 'student')['visible']
            || ! collect($sections)->firstWhere('key', 'subjects')['visible']) {
            throw ValidationException::withMessages([
                'configuracion.sections' => 'Incluye cada bloque una sola vez; estudiante y asignaturas deben permanecer visibles.',
            ]);
        }
        $data['nombre'] = trim($data['nombre']);
        $data['configuracion']['heading'] = trim($data['configuracion']['heading']);
        $data['configuracion']['subheading'] = trim($data['configuracion']['subheading'] ?? '');
        if ($data['nombre'] === '' || $data['configuracion']['heading'] === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre y el título no pueden estar vacíos.']);
        }

        return $data;
    }
}
