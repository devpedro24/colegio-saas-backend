<?php

declare(strict_types=1);

namespace App\Models\Ingreso;

use App\Models\Academico\Grado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GradoInstitucional extends Model
{
    protected $table = 'ingreso_grados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edad_min' => 'integer', 'edad_max' => 'integer'];
    }

    public static function keyFor(string $level, string $name): string
    {
        return hash('sha256', Str::lower(Str::squish($level)).'|'.Str::lower(Str::squish($name)));
    }

    /** Annual copies retain the reference; new annual grades reuse an exact school identity. */
    public static function link(Grado $grade): void
    {
        if ($grade->ingreso_grado_id || ! $grade->getConnection()->getSchemaBuilder()->hasTable('ingreso_grados')) {
            return;
        }
        $level = $grade->nivel()->withTrashed()->value('nivel_educativo') ?? '';
        $key = self::keyFor($level, $grade->nombre);
        // Annual rows also retain names introduced by later renames. Reuse their
        // explicit stable reference instead of creating an unrestricted duplicate.
        $matches = Grado::withTrashed()->join('niveles', 'niveles.id', '=', 'grados.nivel_id')
            ->where('niveles.nivel_educativo', $level)->whereNotNull('grados.ingreso_grado_id')
            ->get(['grados.nombre', 'grados.ingreso_grado_id'])
            ->filter(fn ($known) => self::keyFor($level, $known->nombre) === $key)
            ->pluck('ingreso_grado_id')->unique();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['nombre' => __('intake.ambiguousGrade')]);
        }
        if ($matches->count() === 1) {
            $grade->ingreso_grado_id = $matches->first();

            return;
        }
        $record = self::firstOrCreate(['clave' => $key], [
            'nombre' => $grade->nombre, 'nivel' => $level,
        ]);
        $grade->ingreso_grado_id = $record->id;
    }
}
