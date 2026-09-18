<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Materia extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = ['area_id', 'nivel_id', 'nombre', 'codigo', 'intensidad_horaria', 'estado'];

    protected function casts(): array
    {
        return ['area_id' => 'integer', 'nivel_id' => 'integer', 'intensidad_horaria' => 'integer'];
    }

    /** @return BelongsTo<Area, Materia> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<Nivel, Materia> */
    public function nivel(): BelongsTo
    {
        return $this->belongsTo(Nivel::class);
    }
}
