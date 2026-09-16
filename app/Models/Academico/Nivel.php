<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Nivel educativo del colegio (preescolar, primaria, secundaria, media, ...).
 *
 * `nivel_educativo` es la clave estable que otras tablas usan como string
 * (escalas_valorativas, modelos_pedagogicos); aquí se liga a una fila real.
 * Vive en la BD del tenant.
 *
 * @property int $id
 * @property string $nivel_educativo
 * @property string $nombre
 * @property int $orden
 * @property string $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Grado> $grados
 */
class Nivel extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    /** Valores canónicos usados por otras tablas (escalas/modelos). */
    public const NIVEL_PREESCOLAR = 'preescolar';

    public const NIVEL_PRIMARIA = 'primaria';

    public const NIVEL_SECUNDARIA = 'secundaria';

    public const NIVEL_MEDIA = 'media';

    protected $table = 'niveles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nivel_educativo',
        'nombre',
        'orden',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    /**
     * @return HasMany<Grado>
     */
    public function grados(): HasMany
    {
        return $this->hasMany(Grado::class)->orderBy('orden');
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
