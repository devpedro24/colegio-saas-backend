<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AulaRespuestaMedio extends Model
{
    protected $table = 'aula_respuesta_medios';
    protected $fillable = ['intento_id', 'pregunta_id', 'archivo_token', 'nombre', 'mime'];

    public function intento(): BelongsTo { return $this->belongsTo(AulaIntento::class); }
    public function pregunta(): BelongsTo { return $this->belongsTo(AulaPregunta::class); }
}
