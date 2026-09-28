<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActividadEvaluacion extends Model
{
    protected $table = 'actividades_evaluacion';

    protected $fillable = ['componente_id', 'nombre', 'fecha', 'peso'];

    protected $casts = ['peso' => 'decimal:4'];

    public function componente(): BelongsTo
    {
        return $this->belongsTo(ComponenteEvaluacion::class, 'componente_id');
    }
}
