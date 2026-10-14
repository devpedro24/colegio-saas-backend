<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

class EventoCorreo extends Model
{
    protected $table = 'evento_correos';
    protected $guarded = ['id'];
    protected $hidden = ['email'];
    protected $casts = ['email' => 'encrypted', 'disponible_en' => 'datetime', 'enviado_en' => 'datetime', 'intentos' => 'integer'];
}
