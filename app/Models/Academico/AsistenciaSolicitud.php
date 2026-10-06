<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class AsistenciaSolicitud extends Model
{
    protected $table = 'asistencia_solicitudes';

    protected $guarded = ['id'];
}
