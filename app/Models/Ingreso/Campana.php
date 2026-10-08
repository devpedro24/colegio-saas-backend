<?php

namespace App\Models\Ingreso;

use Illuminate\Database\Eloquent\Model;

final class Campana extends Model
{
    protected $table = 'ingreso_campanas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuracion' => 'array', 'abierta' => 'boolean', 'desde' => 'date', 'hasta' => 'date'];
    }
}
