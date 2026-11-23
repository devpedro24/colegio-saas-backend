<?php

namespace App\Models\Ingreso;

use App\Models\Academico\AnoLectivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Campana extends Model
{
    protected $table = 'ingreso_campanas';

    protected $guarded = ['id'];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }

    protected function casts(): array
    {
        return ['configuracion' => 'array', 'abierta' => 'boolean', 'desde' => 'date', 'hasta' => 'date',
            'apertura_en' => 'datetime', 'cierre_en' => 'datetime', 'correcciones_hasta' => 'datetime'];
    }
}
