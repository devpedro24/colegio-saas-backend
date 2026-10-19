<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class IndicadorVinculo extends Model
{
    protected $table = 'indicadores_vinculos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['periodos_orden' => 'array'];
    }
}
