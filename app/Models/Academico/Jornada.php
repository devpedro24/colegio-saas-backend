<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Jornada académica (mañana/tarde/noche) de una sede.
 * Agrupa los bloques horarios. Vive en la BD del tenant.
 *
 * @property int $id
 * @property int $sede_id
 * @property string $nombre
 * @property string|null $hora_inicio
 * @property string|null $hora_fin
 * @property string $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Sede $sede
 * @property-read Collection<int, BloqueHorario> $bloques
 */
class Jornada extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_INACTIVA = 'inactiva';

    protected $table = 'jornadas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
        'nombre',
        'hora_inicio',
        'hora_fin',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sede_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sede, Jornada>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * @return HasMany<BloqueHorario>
     */
    public function bloques(): HasMany
    {
        return $this->hasMany(BloqueHorario::class)->orderBy('orden');
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA;
    }
}
