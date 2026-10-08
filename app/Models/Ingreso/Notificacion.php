<?php

namespace App\Models\Ingreso;

use Illuminate\Database\Eloquent\Model;

final class Notificacion extends Model
{
    protected $table = 'ingreso_notificaciones';

    protected $guarded = ['id'];

    protected $hidden = ['contenido'];

    protected function casts(): array
    {
        return ['contenido' => 'encrypted', 'enviada_en' => 'datetime'];
    }
}
