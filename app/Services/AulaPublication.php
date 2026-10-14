<?php

namespace App\Services;

use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;

final class AulaPublication
{
    public static function stamp(AulaRecurso $resource): void
    {
        $now = now('UTC');
        // Preserve the first actual publication. Future dates may still be rescheduled.
        if ($resource->publicado_en && $resource->publicado_en->lte($now)) return;
        $section = AulaSeccion::find($resource->seccion_id);
        if (! $resource->visible_estudiantes || ! in_array($resource->estado, ['publicado', 'programado', 'cerrado'], true)
            || ($resource->estado === 'programado' && ! $resource->disponible_desde)
            || ! $section?->visible_estudiantes) {
            $resource->publicado_en = null;
            return;
        }
        $resource->publicado_en = $resource->disponible_desde?->gt($now) ? $resource->disponible_desde : $now;
        $resource->publicacion_estimada = false;
    }
}
