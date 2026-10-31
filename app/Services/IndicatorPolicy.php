<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Scope and teacher editing are configured independently for each academic year. */
final class IndicatorPolicy
{
    public static function forYear(int $yearId): array
    {
        $row = DB::table('politicas_indicadores')->where('ano_lectivo_id', $yearId)->first();
        $gradeCount = DB::table('indicadores_vinculos')->where('ano_lectivo_id', $yearId)
            ->where('estado', 'activo')->whereNull('grupo_id')->count();
        $groupCount = DB::table('indicadores_vinculos')->where('ano_lectivo_id', $yearId)
            ->where('estado', 'activo')->whereNotNull('grupo_id')->count()
            + DB::table('indicadores_contenidos_grupo')->where('ano_lectivo_id', $yearId)->count();
        $inferred = $gradeCount && $groupCount ? 'mixto' : ($gradeCount ? 'grado' : 'grupo');
        $scope = $row?->alcance ?? $inferred;

        return [
            'alcance' => $scope,
            'docente_puede_editar' => $scope !== 'grado' && (bool) ($row?->docente_puede_editar
                ?? DB::table('configuracion_boletines')->where('id', 1)->value('docente_edita_indicadores_grupo') ?? false),
            'version' => (int) ($row?->version ?? 0),
            'configurado' => $row !== null,
            'indicadores_grado' => $gradeCount,
            'indicadores_grupo' => $groupCount,
        ];
    }
}
