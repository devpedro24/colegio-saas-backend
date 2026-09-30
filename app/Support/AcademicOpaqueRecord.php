<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Public academic DTO used only by endpoints explicitly requesting opaque IDs. */
final class AcademicOpaqueRecord
{
    private const FIELDS = [
        'ano-lectivo' => ['nombre', 'tipo_calendario', 'fecha_inicio', 'fecha_fin', 'estado'],
        'sede' => ['nombre', 'direccion', 'telefono', 'coordinador_name', 'coordinador_email',
            'estado', 'tenant_slug', 'tenant_domain', 'tenant_status'],
        'jornada' => ['nombre', 'hora_inicio', 'hora_fin', 'estado', 'ano_lectivo_id', 'sede_id', 'sede'],
        'nivel' => ['nombre', 'nivel_educativo', 'estado', 'ano_lectivo_id', 'grados_count', 'grados'],
        'grado' => ['nombre', 'codigo', 'estado', 'ano_lectivo_id', 'nivel_id', 'grupos_count', 'nivel'],
        'grupo' => ['nombre', 'cupo_maximo', 'estado', 'ano_lectivo_id', 'grado_id',
            'jornada_id', 'sede_id', 'grado', 'jornada', 'sede', 'ano_lectivo'],
        'bloque-horario' => ['nombre', 'hora_inicio', 'hora_fin', 'es_descanso', 'estado',
            'ano_lectivo_id', 'jornada_id', 'jornada'],
        'espacio-fisico' => ['nombre', 'tipo', 'capacidad', 'ubicacion', 'estado',
            'ano_lectivo_id', 'sede_id', 'sede'],
        'area' => ['nombre', 'descripcion', 'estado', 'ano_lectivo_id', 'materias_count'],
        'materia' => ['nombre', 'codigo', 'intensidad_horaria', 'estado',
            'ano_lectivo_id', 'area_id', 'nivel_id', 'area', 'nivel'],
    ];

    private const FOREIGN_KEYS = [
        'ano_lectivo_id' => 'ano-lectivo',
        'sede_id' => 'sede',
        'jornada_id' => 'jornada',
        'nivel_id' => 'nivel',
        'grado_id' => 'grado',
        'grupo_id' => 'grupo',
        'area_id' => 'area',
        'materia_id' => 'materia',
        'bloque_horario_id' => 'bloque-horario',
        'espacio_fisico_id' => 'espacio-fisico',
    ];

    private const RELATIONS = [
        'ano_lectivo' => 'ano-lectivo',
        'sede' => 'sede',
        'jornada' => 'jornada',
        'nivel' => 'nivel',
        'grado' => 'grado',
        'grados' => 'grado',
        'grupo' => 'grupo',
        'area' => 'area',
        'materia' => 'materia',
        'materias' => 'materia',
    ];

    /** @return array<string, mixed> */
    public static function present(Model|array $record, string $resource): array
    {
        $data = $record instanceof Model ? $record->toArray() : $record;
        $id = $record instanceof Model ? $record->getKey() : ($data['id'] ?? null);
        $public = [];
        foreach (self::FIELDS[$resource] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (isset(self::FOREIGN_KEYS[$key])) {
                $public[substr($key, 0, -3).'_token'] = $value === null
                    ? null : OpaqueUrlToken::for(self::FOREIGN_KEYS[$key], $value);

                continue;
            }
            if (isset(self::RELATIONS[$key]) && is_array($value)) {
                $public[$key] = array_is_list($value)
                    ? array_map(fn ($child) => self::present($child, self::RELATIONS[$key]), $value)
                    : self::present($value, self::RELATIONS[$key]);

                continue;
            }
            $public[$key] = $value;
        }
        if ($id !== null) {
            $public['url_token'] = OpaqueUrlToken::for($resource, $id);
        }

        return $public;
    }
}
