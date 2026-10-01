<?php

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

class Calificacion extends Model
{
    protected $table = 'calificaciones';

    protected $fillable = ['actividad_id', 'matricula_id', 'valor', 'observacion', 'updated_by', 'version'];

    protected $casts = ['valor' => \App\Support\AcademicDecimal::class, 'version' => 'integer'];
}
