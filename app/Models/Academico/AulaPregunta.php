<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

class AulaPregunta extends Model
{
    protected $table = 'aula_preguntas';
    protected $fillable = ['recurso_id', 'tipo', 'enunciado', 'opciones', 'respuesta_correcta', 'puntos', 'orden'];
    protected $casts = ['opciones' => 'array', 'respuesta_correcta' => 'array'];
}
