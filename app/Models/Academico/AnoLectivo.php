<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Año lectivo del colegio (RN-PA-001..008). Vive en la BD del tenant.
 *
 * Agrupa los periodos académicos y define el calendario (A o B) del año.
 * Solo un año puede estar 'en_curso' a la vez (RN-PA-001).
 *
 * @property int $id
 * @property string $nombre
 * @property string $tipo_calendario
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 * @property int $num_periodos
 * @property bool $tiene_quinto_periodo
 * @property string $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Periodo> $periodos
 */
class AnoLectivo extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Máquina de estados del año lectivo (FSM):
     * planificado → en_curso → cerrado → archivado.
     */
    public const ESTADO_PLANIFICADO = 'planificado';

    public const ESTADO_EN_CURSO = 'en_curso';

    public const ESTADO_CERRADO = 'cerrado';

    public const ESTADO_ARCHIVADO = 'archivado';

    /** Tipos de calendario académico soportados (periodos-academicos.md). */
    public const TIPO_A = 'A';   // febrero–noviembre; nombre "AAAA"

    public const TIPO_B = 'B';   // septiembre–junio; nombre "AAAA-AAAA"

    protected $table = 'anos_lectivos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'tipo_calendario',
        'fecha_inicio',
        'fecha_fin',
        'num_periodos',
        'tiene_quinto_periodo',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'num_periodos' => 'integer',
            'tiene_quinto_periodo' => 'boolean',
        ];
    }

    /**
     * Periodos académicos del año, en orden.
     *
     * @return HasMany<Periodo>
     */
    public function periodos(): HasMany
    {
        return $this->hasMany(Periodo::class)->orderBy('orden');
    }

    /**
     * Filtra el (único) año lectivo en curso (RN-PA-001).
     *
     * @param  Builder<AnoLectivo>  $query
     * @return Builder<AnoLectivo>
     */
    public function scopeEnCurso(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_EN_CURSO);
    }

    /** ¿El año está cerrado o archivado? (datos inmutables — RN-PA-006). */
    public function estaCerrado(): bool
    {
        return in_array($this->estado, [self::ESTADO_CERRADO, self::ESTADO_ARCHIVADO], true);
    }
}
