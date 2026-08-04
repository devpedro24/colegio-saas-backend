<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Espacio físico (salón, laboratorio, biblioteca, auditorio, ...).
 * Se programa en el horario (Bloque C). Vive en la BD del tenant.
 *
 * @property int                             $id
 * @property int|null                        $sede_id
 * @property string                          $nombre
 * @property string                          $tipo
 * @property int|null                        $capacidad
 * @property string|null                     $ubicacion
 * @property string                          $estado
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 * @property \Illuminate\Support\Carbon|null  $deleted_at
 * @property-read \App\Models\Academico\Sede|null $sede
 */
class EspacioFisico extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TIPO_AULA = 'aula';
    public const TIPO_LABORATORIO = 'laboratorio';
    public const TIPO_BIBLIOTECA = 'biblioteca';
    public const TIPO_AUDITORIO = 'auditorio';
    public const TIPO_PATIO = 'patio';
    public const TIPO_OTRO = 'otro';

    public const ESTADO_DISPONIBLE = 'disponible';
    public const ESTADO_OCUPADO = 'ocupado';
    public const ESTADO_MANTENIMIENTO = 'mantenimiento';
    public const ESTADO_INACTIVO = 'inactivo';

    public const TIPOS = [
        self::TIPO_AULA,
        self::TIPO_LABORATORIO,
        self::TIPO_BIBLIOTECA,
        self::TIPO_AUDITORIO,
        self::TIPO_PATIO,
        self::TIPO_OTRO,
    ];

    public const ESTADOS = [
        self::ESTADO_DISPONIBLE,
        self::ESTADO_OCUPADO,
        self::ESTADO_MANTENIMIENTO,
        self::ESTADO_INACTIVO,
    ];

    protected $table = 'espacios_fisicos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sede_id',
        'nombre',
        'tipo',
        'capacidad',
        'ubicacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sede_id' => 'integer',
            'capacidad' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sede, EspacioFisico>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }
}
