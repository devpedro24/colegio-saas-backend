<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bloque horario de una jornada (bloque 1, descanso, bloque 2, ...).
 * Base del horario semanal (Bloque C). Vive en la BD del tenant.
 *
 * @property int                             $id
 * @property int                             $jornada_id
 * @property string                          $nombre
 * @property string                          $hora_inicio
 * @property string                          $hora_fin
 * @property bool                            $es_descanso
 * @property int                             $orden
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 * @property \Illuminate\Support\Carbon|null  $deleted_at
 * @property-read \App\Models\Academico\Jornada $jornada
 */
class BloqueHorario extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $table = 'bloques_horarios';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'jornada_id',
        'nombre',
        'hora_inicio',
        'hora_fin',
        'es_descanso',
        'orden',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jornada_id' => 'integer',
            'es_descanso' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Jornada, BloqueHorario>
     */
    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class);
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
