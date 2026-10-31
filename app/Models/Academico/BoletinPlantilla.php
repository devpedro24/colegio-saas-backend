<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BoletinPlantilla extends Model
{
    use SoftDeletes;

    protected $table = 'boletin_plantillas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuracion' => 'array'];
    }
}
