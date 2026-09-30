<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Academico\Evento;
use App\Models\Academico\Grupo;

final class EventOpaquePresenter
{
    /** @param array<int, array<string, mixed>>|null $files
     *  @return array<string, mixed>
     */
    public static function present(Evento $event, ?array $files = null, ?bool $canEdit = null): array
    {
        $event->loadMissing('grupos:id,nombre');
        $groups = $event->grupos->map(fn (Grupo $group) => [
            'url_token' => OpaqueUrlToken::for('grupo', $group->id),
            'nombre' => $group->nombre,
        ])->all();
        $data = [
            'url_token' => OpaqueUrlToken::for('evento', $event->id),
            'titulo' => $event->titulo,
            'descripcion' => $event->descripcion,
            'fecha' => $event->fecha,
            'hora_inicio' => $event->hora_inicio,
            'hora_fin' => $event->hora_fin,
            'categoria' => $event->categoria,
            'institucional' => (bool) $event->institucional,
            'materia_token' => $event->materia_id ? OpaqueUrlToken::for('materia', $event->materia_id) : null,
            'created_by_token' => OpaqueUrlToken::for('usuario', $event->created_by),
            'grupo_tokens' => array_column($groups, 'url_token'),
            'grupos' => $groups,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
        if ($files !== null) {
            $data['archivos'] = $files;
        }
        if ($canEdit !== null) {
            $data['puede_editar'] = $canEdit;
        }

        return $data;
    }
}
