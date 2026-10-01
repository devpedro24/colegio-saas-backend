<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActividadEvaluacion extends Model
{
    protected $table = 'actividades_evaluacion';

    protected $fillable = ['componente_id', 'nombre', 'fecha', 'peso', 'version'];

    protected $casts = ['peso' => \App\Support\AcademicDecimal::class, 'version' => 'integer'];

    public function componente(): BelongsTo
    {
        return $this->belongsTo(ComponenteEvaluacion::class, 'componente_id');
    }
}
