<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grupo (curso concreto): grado + año lectivo + jornada (RN-JO-002).
 * Unidad sobre la que se asignan docentes y se agrupan estudiantes.
 * Vive en la BD del tenant.
 *
 * @property int                             $id
 * @property int                             $grado_id
 * @property int                             $ano_lectivo_id
 * @property int|null                        $jornada_id
 * @property int|null                        $sede_id
 * @property string                          $nombre
 * @property int|null                        $cupo_maximo
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 * @property \Illuminate\Support\Carbon|null  $deleted_at
 * @property-read \App\Models\Academico\Grado $grado
 * @property-read \App\Models\Academico\AnoLectivo $anoLectivo
 * @property-read \App\Models\Academico\Jornada|null $jornada
 * @property-read \App\Models\Academico\Sede|null $sede
 */
class Grupo extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $table = 'grupos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grado_id',
        'ano_lectivo_id',
        'jornada_id',
        'sede_id',
        'nombre',
        'cupo_maximo',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grado_id' => 'integer',
            'ano_lectivo_id' => 'integer',
            'jornada_id' => 'integer',
            'sede_id' => 'integer',
            'cupo_maximo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Grado, Grupo>
     */
    public function grado(): BelongsTo
    {
        return $this->belongsTo(Grado::class);
    }

    /**
     * @return BelongsTo<AnoLectivo, Grupo>
     */
    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    /**
     * @return BelongsTo<Jornada, Grupo>
     */
    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class);
    }

    /**
     * @return BelongsTo<Sede, Grupo>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
