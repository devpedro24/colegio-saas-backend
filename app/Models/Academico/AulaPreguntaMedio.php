<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AulaPreguntaMedio extends Model
{
    protected $table = 'aula_pregunta_medios';
    protected $fillable = ['pregunta_id', 'opcion_indice', 'archivo_token', 'nombre', 'mime', 'autor_id'];

    public function pregunta(): BelongsTo { return $this->belongsTo(AulaPregunta::class); }
}
