<?php

namespace App\Models\Ingreso;

use Illuminate\Database\Eloquent\Model;

final class Documento extends Model
{
    protected $table = 'ingreso_documentos';

    protected $guarded = ['id'];
}
