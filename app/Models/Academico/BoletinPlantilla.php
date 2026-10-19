<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class BoletinPlantilla extends Model
{
    protected $table = 'boletin_plantillas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuracion' => 'array'];
    }
}
