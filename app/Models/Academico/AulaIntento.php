<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AulaIntento extends Model
{
    protected $table = 'aula_intentos';
    protected $fillable = ['recurso_id', 'matricula_id', 'numero', 'estado', 'iniciado_at', 'vence_at',
        'finalizado_at', 'incidentes', 'respuestas', 'revision_preguntas', 'presentacion', 'nota', 'escala_opcion_id', 'pagina_actual', 'version'];
    protected $casts = ['iniciado_at' => 'datetime', 'vence_at' => 'datetime', 'finalizado_at' => 'datetime',
        'respuestas' => 'array', 'revision_preguntas' => 'array', 'presentacion' => 'array', 'incidentes' => 'integer',
        'pagina_actual' => 'integer', 'version' => 'integer'];

    public function recurso(): BelongsTo { return $this->belongsTo(AulaRecurso::class, 'recurso_id'); }
    public function matricula(): BelongsTo { return $this->belongsTo(Matricula::class); }
}
