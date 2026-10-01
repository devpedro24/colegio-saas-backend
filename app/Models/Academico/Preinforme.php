<?php

namespace App\Models\Academico;

use App\Support\AcademicDecimal;
use Illuminate\Database\Eloquent\Model;

class Preinforme extends Model
{
    protected $table = 'preinformes';

    protected $fillable = ['periodo_id', 'nombre', 'orden', 'peso', 'fecha_inicio', 'fecha_fin'];

    protected $casts = ['peso' => AcademicDecimal::class];
}
