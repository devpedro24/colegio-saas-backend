<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

class AulaAdjunto extends Model
{
    protected $table = 'aula_adjuntos';
    protected $fillable = ['recurso_id', 'entrega_id', 'archivo_token', 'nombre', 'autor_id'];
}
