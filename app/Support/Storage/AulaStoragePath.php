<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\Academico\Aula;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\Grupo;
use App\Models\User;

/** Readable classroom hierarchy, with no technical collection folders or visible IDs. */
final class AulaStoragePath
{
    private static function name(string $label): string
    {
        return ReadableStorageName::segment($label);
    }

    private static function group(Grupo $group): string
    {
        $group->loadMissing('grado');

        return self::name('Grado '.$group->grado->nombre.' '.$group->nombre);
    }

    private static function year(Grupo $group): string
    {
        $group->loadMissing('anoLectivo');

        return self::name('Año lectivo '.$group->anoLectivo->nombre);
    }

    private static function distinct(string $title, int $id, iterable $peers): string
    {
        $base = self::name($title);
        $position = 0;
        foreach ($peers as $peer) {
            if ((int) $peer->id <= $id && mb_strtolower(self::name($peer->titulo)) === mb_strtolower($base)) $position++;
        }

        return $position > 1 ? $base.'_'.$position : $base;
    }

    public static function classroom(Aula $aula): string
    {
        $aula->loadMissing(['grupo.grado', 'grupo.anoLectivo', 'materia']);

        return implode('/', [
            'aula',
            self::year($aula->grupo),
            self::group($aula->grupo),
            self::name($aula->materia->nombre),
        ]);
    }

    public static function section(AulaSeccion $section): string
    {
        $section->loadMissing(['aula', 'periodo', 'preinforme']);
        $peers = AulaSeccion::withTrashed()->where('aula_id', $section->aula_id)
            ->where('periodo_id', $section->periodo_id)->where('preinforme_id', $section->preinforme_id)
            ->where('id', '<=', $section->id)->get(['id', 'titulo']);

        return self::classroom($section->aula).'/'.self::name($section->periodo->nombre)
            .'/'.($section->preinforme ? self::name($section->preinforme->nombre) : 'Sin_preinforme')
            .'/'.self::distinct($section->titulo, (int) $section->id, $peers);
    }

    public static function resource(AulaRecurso $resource): string
    {
        $resource->loadMissing('seccion');
        $peers = AulaRecurso::withTrashed()->where('seccion_id', $resource->seccion_id)
            ->where('id', '<=', $resource->id)->get(['id', 'titulo']);

        return self::section($resource->seccion).'/'.self::distinct($resource->titulo, (int) $resource->id, $peers);
    }

    private static function userFolder(User $user, Grupo $group): string
    {
        return implode('/', ['usuarios', self::year($group), self::group($group), self::name($user->name)]);
    }

    public static function submission(AulaEntrega $submission, User $user): string
    {
        $submission->loadMissing(['matricula.grupo', 'recurso.seccion.periodo', 'recurso.seccion.preinforme', 'recurso.seccion.aula.materia']);
        $resource = $submission->recurso;
        $section = $resource->seccion;

        return self::userFolder($user, $submission->matricula->grupo).'/aula/'
            .self::name($section->aula->materia->nombre).'/'.self::name($section->periodo->nombre)
            .'/'.($section->preinforme ? self::name($section->preinforme->nombre) : 'Sin_preinforme')
            .'/'.self::name($section->titulo).'/'.self::name($resource->titulo).'/entregas';
    }

    public static function answer(AulaIntento $attempt, User $user): string
    {
        $attempt->loadMissing(['matricula.grupo', 'recurso.seccion.periodo', 'recurso.seccion.preinforme', 'recurso.seccion.aula.materia']);
        $resource = $attempt->recurso;
        $section = $resource->seccion;

        return self::userFolder($user, $attempt->matricula->grupo).'/aula/'
            .self::name($section->aula->materia->nombre).'/'.self::name($section->periodo->nombre)
            .'/'.($section->preinforme ? self::name($section->preinforme->nombre) : 'Sin_preinforme')
            .'/'.self::name($section->titulo).'/'.self::name($resource->titulo)
            .'/intento_'.$attempt->numero.'/respuestas';
    }
}
