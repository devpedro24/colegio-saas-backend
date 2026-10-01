<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class PromocionAcademica extends Model
{
    protected $table = 'promociones_academicas';

    protected $fillable = ['ano_lectivo_id', 'matricula_id', 'propuesta', 'resultado',
        'grado_destino_id', 'insumos', 'huella', 'motivo', 'reviewed_by',
        'version', 'approved_at'];

    protected function casts(): array
    {
        return ['insumos' => 'array', 'approved_at' => 'datetime', 'version' => 'integer'];
    }
}
