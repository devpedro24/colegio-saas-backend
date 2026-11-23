<?php

namespace App\Models\Academico;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Matricula extends Model
{
    protected $fillable = ['estudiante_id', 'grupo_id', 'ano_lectivo_id', 'estado'];

    public function scopeConAccesoEstudiantil(Builder $query): Builder
    {
        return $query->whereIn('ano_lectivo_id', AnoLectivo::accesiblesAlEstudiante()->select('id'));
    }

    /** Before the next effective move, its origin is the group on that day. */
    public static function rosterOn(int $year, int $group, string $date): Builder
    {
        return static::where('ano_lectivo_id', $year)->where('estado', 'activa')
            ->whereRaw('COALESCE((SELECT m.grupo_origen_id FROM matricula_movimientos m
                WHERE m.matricula_id = matriculas.id AND m.fecha_efectiva > ?
                ORDER BY m.fecha_efectiva, m.version LIMIT 1), matriculas.grupo_id) = ?', [$date, $group]);
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estudiante_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
}
