<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Metodo de aprobacion (BD del tenant) — bloque 5 de configuracion.
 *
 * Como se consolida la aprobacion: promedio simple, ponderado o sumatoria
 * dividida; nota minima; y ambito (materia, area o general). Por ano lectivo.
 *
 * @property int $id
 * @property int $ano_lectivo_id
 * @property string $calculo_nota
 * @property string $nota_minima
 * @property string $ambito
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MetodoAprobacion extends Model
{
    use SoftDeletes;

    protected $table = 'metodos_aprobacion';

    /** Formas de calcular la aprobacion. */
    public const CALCULO_PROMEDIO_SIMPLE = 'promedio_simple';

    public const CALCULO_PONDERADO = 'ponderado';

    public const CALCULO_SUMATORIA = 'sumatoria';

    /** Ambito al que aplica la decision de aprobacion. */
    public const AMBITO_MATERIA = 'materia';

    public const AMBITO_AREA = 'area';

    public const AMBITO_PROMEDIO_GENERAL = 'promedio_general';

    protected $fillable = [
        'ano_lectivo_id',
        'calculo_nota',
        'nota_minima',
        'ambito',
    ];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'nota_minima' => 'decimal:2',
    ];

    /** Ano lectivo al que pertenece este metodo. */
    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }
}
