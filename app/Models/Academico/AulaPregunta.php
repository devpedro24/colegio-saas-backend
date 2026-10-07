<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AulaPregunta extends Model
{
    protected $table = 'aula_preguntas';
    protected $fillable = ['recurso_id', 'tipo', 'enunciado', 'opciones', 'respuesta_correcta', 'puntos',
        'puntajes_opciones', 'rubrica', 'orden'];
    protected $casts = ['opciones' => 'array', 'respuesta_correcta' => 'array',
        'puntajes_opciones' => 'array', 'rubrica' => 'array'];

    public function recurso(): BelongsTo { return $this->belongsTo(AulaRecurso::class); }
    public function medios(): HasMany { return $this->hasMany(AulaPreguntaMedio::class, 'pregunta_id'); }
}
