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
 * Cuando `tenant_id` no es NULL, la sede es un tenant hijo (tipo 'sede')
 * con su propia BD, RBAC y subdominio <slug>.<subdominio-colegio>.
 *
 * @property int                             $id
 * @property string                          $nombre
 * @property string|null                     $direccion
 * @property string|null                     $telefono
 * @property string|null                     $responsable
 * @property string|null                     $tenant_id
 * @property string|null                     $coordinador_email
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
        'coordinador_name',
        'coordinador_email',
        'tenant_id',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['hashed_id'];

    /**
     * @var list<string>
     */
    protected $appends = ['hashed_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /** ID opaco para URLs publicas (evita exponer el auto-increment). */
    public function getHashedIdAttribute(): string
    {
        return rtrim(strtr(base64_encode((string) $this->id), '+/', '-_'), '=');
    }

    /**
     * Resuelve una sede por ID numerico, hashed_id (base64url) o hashed_id
     * legacy con padding `=`.
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        if (is_numeric($value)) {
            return $this->newQuery()->where('id', (int) $value)->first();
        }

        $decoded = base64_decode(
            strtr((string) $value, '-_', '+/') . str_repeat('=', (4 - strlen((string) $value) % 4) % 4),
            true,
        );

        if ($decoded !== false && is_numeric($decoded)) {
            return $this->newQuery()->where('id', (int) $decoded)->first();
        }

        return null;
    }

    /** True cuando esta sede es un tenant hijo (sede adicional). */
    public function esTenantHijo(): bool
    {
        return $this->tenant_id !== null;
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
