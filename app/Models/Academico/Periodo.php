<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Periodo académico (periodos-academicos.md). Vive en la BD del tenant.
 *
 * Pertenece a un año lectivo; los periodos son contiguos y quedan dentro del
 * rango de fechas del año (RN-PA-003). Un periodo cerrado es inmutable (RN-PA-006).
 *
 * @property int $id
 * @property int $ano_lectivo_id
 * @property string $nombre
 * @property int $orden
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 * @property string|null $peso
 * @property string $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AnoLectivo $anoLectivo
 */
class Periodo extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Máquina de estados del periodo (FSM):
     * planificado → abierto → cerrado.
     */
    public const ESTADO_PLANIFICADO = 'planificado';

    public const ESTADO_ABIERTO = 'abierto';

    public const ESTADO_CERRADO = 'cerrado';

    protected $table = 'periodos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ano_lectivo_id',
        'nombre',
        'orden',
        'fecha_inicio',
        'fecha_fin',
        'peso',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ano_lectivo_id' => 'integer',
            'orden' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    /**
     * Año lectivo al que pertenece el periodo.
     *
     * @return BelongsTo<AnoLectivo, Periodo>
     */
    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }

    /** ¿El periodo está cerrado? (datos inmutables — RN-PA-006). */
    public function estaCerrado(): bool
    {
        return $this->estado === self::ESTADO_CERRADO;
    }
}
