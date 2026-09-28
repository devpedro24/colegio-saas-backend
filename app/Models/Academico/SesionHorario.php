<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SesionHorario extends Model
{
    use SoftDeletes;

    protected $table = 'sesiones_horario';

    protected $fillable = ['asignacion_id', 'grupo_id', 'materia_id', 'docente_id', 'dia', 'bloque_horario_id', 'hora_inicio', 'hora_fin', 'espacio_fisico_id'];

    public const DIAS = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(AsignacionDocente::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'docente_id');
    }

    public function bloque(): BelongsTo
    {
        return $this->belongsTo(BloqueHorario::class, 'bloque_horario_id')->withTrashed();
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(EspacioFisico::class, 'espacio_fisico_id');
    }
}
