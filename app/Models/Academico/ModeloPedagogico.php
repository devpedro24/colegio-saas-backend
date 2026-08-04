<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo pedagogico (BD del tenant) — bloque 6 de configuracion.
 *
 * Organizacion academica del nivel: docente unico, salon fijo y director de
 * grupo. Se versiona por ano lectivo y aplica por nivel (`nivel_id` nullable =
 * aplica a todo el colegio).
 *
 * @property int         $id
 * @property int         $ano_lectivo_id
 * @property ?string     $nivel_educativo
 * @property bool        $docente_unico
 * @property bool        $salon_fijo
 * @property bool        $tiene_director_grupo
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ModeloPedagogico extends Model
{
    use SoftDeletes;

    protected $table = 'modelos_pedagogicos';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_educativo',
        'docente_unico',
        'salon_fijo',
        'tiene_director_grupo',
    ];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'docente_unico' => 'boolean',
        'salon_fijo' => 'boolean',
        'tiene_director_grupo' => 'boolean',
    ];

    /** Ano lectivo al que pertenece este modelo. */
    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Academico\AnoLectivo::class, 'ano_lectivo_id');
    }
}
