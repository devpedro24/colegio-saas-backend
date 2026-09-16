<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Escala valorativa (BD del tenant) — bloque 4 de configuracion.
 *
 * Como se califica: numerica o por imagenes, con rango, decimales y nota de
 * aprobacion. Se versiona por ano lectivo y puede variar por nivel (RN-CC-003;
 * `nivel_id` nullable = aplica a todo el colegio).
 *
 * @property int $id
 * @property int $ano_lectivo_id
 * @property ?string $nivel_educativo
 * @property string $nombre
 * @property string $tipo
 * @property ?string $valor_min
 * @property ?string $valor_max
 * @property ?int $decimales
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class EscalaValorativa extends Model
{
    use SoftDeletes;

    protected $table = 'escalas_valorativas';

    /** Tipos de escala admitidos. */
    public const TIPO_NUMERICA = 'numerica';

    public const TIPO_IMAGENES = 'imagenes';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_educativo',
        'nombre',
        'tipo',
        'valor_min',
        'valor_max',
        'decimales',
    ];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'valor_min' => 'decimal:2',
        'valor_max' => 'decimal:2',
        'decimales' => 'integer',
    ];

    /** Ano lectivo al que pertenece esta escala. */
    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }
}
