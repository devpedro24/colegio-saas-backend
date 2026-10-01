<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Active subjects enrolled in the group's grade curriculum, shared by pickers and writes. */
final class GroupSubjectScope
{
    public static function apply(Builder $query, Grupo $group): Builder
    {
        // A group may have changed grade while retaining a previously loaded relation.
        // Resolve the current grade before calculating subject compatibility.
        $group->load('grado:id,nivel_id');
        $query->where('ano_lectivo_id', $group->ano_lectivo_id)->where('estado', 'activo')
            ->where(fn (Builder $q) => $q->whereNull('nivel_id')->orWhere('nivel_id', $group->grado?->nivel_id))
            ->whereIn('id', DB::table('materias_curriculares')
                ->where('ano_lectivo_id', $group->ano_lectivo_id)
                ->where('grado_id', $group->grado_id)
                ->select('materia_id'));

        return $query;
    }

    public static function assert(Grupo $group, Materia $subject): void
    {
        abort_unless(self::apply(Materia::query(), $group)->whereKey($subject->id)->exists(), 422,
            'La asignatura debe estar activa e inscrita en el currículo de este grado y año lectivo.');
    }
}
