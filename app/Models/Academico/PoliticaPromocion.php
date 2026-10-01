<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class PoliticaPromocion extends Model
{
    protected $table = 'politicas_promocion';

    protected $fillable = ['ano_lectivo_id', 'max_reprobadas', 'materias_obligatorias',
        'promedio_minimo', 'version'];

    protected function casts(): array
    {
        return ['materias_obligatorias' => 'array', 'max_reprobadas' => 'integer',
            'promedio_minimo' => 'decimal:8', 'version' => 'integer'];
    }
}
