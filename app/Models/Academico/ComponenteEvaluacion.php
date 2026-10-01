<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponenteEvaluacion extends Model
{
    protected $table = 'componentes_evaluacion';

    protected $fillable = ['asignacion_id', 'periodo_id', 'componente_preparado_id', 'nombre', 'modo', 'peso', 'preinforme_id', 'es_directo', 'version'];

    protected $casts = ['peso' => \App\Support\AcademicDecimal::class, 'es_directo' => 'boolean', 'version' => 'integer'];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(AsignacionDocente::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function actividades(): HasMany
    {
        // La fecha o el porcentaje pueden cambiar; la posición de la columna no.
        return $this->hasMany(ActividadEvaluacion::class, 'componente_id')->orderBy('id');
    }
}
