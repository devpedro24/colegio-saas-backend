<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Datos institucionales del colegio (BD del tenant) — bloque 1 de configuracion.
 *
 * Singleton: existe una sola fila por colegio. No se versiona por ano lectivo.
 * `name` y `nit` viven en el tenant central; aqui va el resto de la ficha.
 *
 * @property int $id
 * @property ?string $nombre
 * @property ?string $nit
 * @property ?string $resolucion_men
 * @property ?string $direccion
 * @property ?string $telefono
 * @property ?string $correo
 * @property ?string $logo_principal
 * @property ?string $logo_documentos
 * @property ?string $isotipo
 * @property ?array<string,mixed> $colores
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DatosInstitucionales extends Model
{
    protected $table = 'datos_institucionales';

    protected $fillable = [
        'nombre',
        'nit',
        'resolucion_men',
        'direccion',
        'telefono',
        'correo',
        'logo_principal',
        'logo_documentos',
        'isotipo',
        'colores',
    ];

    protected $casts = [
        'colores' => 'array',
    ];
}
