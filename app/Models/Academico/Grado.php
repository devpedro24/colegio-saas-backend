<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grado académico (transición, 6.º, 7.º, ...) de un nivel.
 * El grupo concreto de un año lectivo se modela en `grupos` (RN-JO-002).
 * Vive en la BD del tenant.
 *
 * @property int                             $id
 * @property int                             $nivel_id
 * @property string                          $nombre
 * @property string|null                     $codigo
 * @property int                             $orden
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 * @property \Illuminate\Support\Carbon|null  $deleted_at
 * @property-read \App\Models\Academico\Nivel $nivel
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Academico\Grupo> $grupos
 */
class Grado extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $table = 'grados';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nivel_id',
        'nombre',
        'codigo',
        'orden',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nivel_id' => 'integer',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Nivel, Grado>
     */
    public function nivel(): BelongsTo
    {
        return $this->belongsTo(Nivel::class);
    }

    /**
     * @return HasMany<Grupo>
     */
    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
