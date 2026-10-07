<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AulaRecurso extends Model
{
    use SoftDeletes;
    protected $table = 'aula_recursos';
    protected $fillable = ['seccion_id', 'tipo', 'titulo', 'contenido', 'configuracion', 'estado', 'visible_estudiantes',
        'calificable', 'llevar_planilla', 'actividad_id', 'peso', 'disponible_desde', 'disponible_hasta', 'fecha_limite',
        'zona_publicacion', 'orden', 'autor_id', 'recurso_origen_id', 'version', 'deleted_with_section'];
    protected $casts = ['contenido' => 'array', 'configuracion' => 'array', 'visible_estudiantes' => 'boolean',
        'calificable' => 'boolean', 'llevar_planilla' => 'boolean', 'disponible_desde' => 'datetime',
        'disponible_hasta' => 'datetime', 'fecha_limite' => 'datetime', 'version' => 'integer',
        'deleted_with_section' => 'boolean'];

    public function seccion(): BelongsTo { return $this->belongsTo(AulaSeccion::class, 'seccion_id'); }
    public function preguntas(): HasMany { return $this->hasMany(AulaPregunta::class, 'recurso_id')->orderBy('orden')->orderBy('id'); }
    public function intentos(): HasMany { return $this->hasMany(AulaIntento::class, 'recurso_id'); }
    public function entregas(): HasMany { return $this->hasMany(AulaEntrega::class, 'recurso_id'); }
}
