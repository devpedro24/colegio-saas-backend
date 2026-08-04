<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sede del colegio (multi-sede). Raíz de la jerarquía
 * Sede→Jornada→Nivel→Grado→Grupo. Vive en la BD del tenant.
 *
 * @property int                             $id
 * @property string                          $nombre
 * @property string|null                     $direccion
 * @property string|null                     $telefono
 * @property string|null                     $responsable
 * @property bool                            $es_principal
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 * @property \Illuminate\Support\Carbon|null  $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Academico\Jornada> $jornadas
 */
class Sede extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ESTADO_ACTIVA = 'activa';
    public const ESTADO_INACTIVA = 'inactiva';

    protected $table = 'sedes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
        'responsable',
        'es_principal',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Jornada>
     */
    public function jornadas(): HasMany
    {
        return $this->hasMany(Jornada::class)->orderBy('nombre');
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA;
    }
}
