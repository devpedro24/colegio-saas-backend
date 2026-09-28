<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponenteEvaluacion extends Model
{
    protected $table = 'componentes_evaluacion';

    protected $fillable = ['asignacion_id', 'periodo_id', 'nombre', 'modo', 'peso'];

    protected $casts = ['peso' => 'decimal:4'];

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
        return $this->hasMany(ActividadEvaluacion::class, 'componente_id');
    }
}
