<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AulaEntrega extends Model
{
    protected $table = 'aula_entregas';
    protected $fillable = ['recurso_id', 'matricula_id', 'texto', 'nota', 'escala_opcion_id', 'retroalimentacion', 'estado', 'entregada_at', 'calificada_por', 'version'];
    protected $casts = ['entregada_at' => 'datetime', 'version' => 'integer'];

    public function recurso(): BelongsTo { return $this->belongsTo(AulaRecurso::class, 'recurso_id'); }
    public function matricula(): BelongsTo { return $this->belongsTo(Matricula::class); }
    public function escalaOpcion(): BelongsTo { return $this->belongsTo(EscalaOpcion::class, 'escala_opcion_id'); }
}
