<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario (BD central = superadmin; BD del tenant = personal del colegio).
 *
 * Ciclo de vida (D-USER-FSM, ciclo-de-vida-de-usuario.md):
 *   pending → active → suspended ↔ active; active ↔ inactive;
 *   {active, suspended, inactive} → deleted.
 * Todas las transiciones son manuales (sin automatismos de inactividad).
 * El borrado es LOGICO (SoftDeletes, RG-004): la fila sobrevive durante la
 * ventana de retencion y luego una purga fisica la elimina (RN-BR-005).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $google_id
 * @property string|null $google_email
 * @property Carbon|null $google_linked_at
 * @property string $password
 * @property string|null $role
 * @property string $status
 * @property bool $must_change_password
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Estados del ciclo de vida del usuario (D-USER-FSM). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DELETED = 'deleted';

    /** Email legacy del usuario sombra (se conserva para migraciones/limpieza). */
    public const PLATFORM_SUPERADMIN_EMAIL = 'superadmin@plataforma.local';

    public static function impersonationShadowEmail(int|string $superadminId): string
    {
        return 'superadmin+'.preg_replace('/[^0-9]/', '', (string) $superadminId).'@plataforma.local';
    }

    /** Namespace reservado para identidades tecnicas de suplantacion. */
    public static function isImpersonationShadowEmail(?string $email): bool
    {
        return preg_match(
            '/^superadmin(?:\+[^@\s]+)?@plataforma\.local$/i',
            trim((string) $email),
        ) === 1;
    }

    public function isImpersonationShadow(): bool
    {
        return self::isImpersonationShadowEmail((string) $this->email);
    }

    public function scopeWithoutImpersonationShadows(Builder $query): Builder
    {
        return $query
            ->whereRaw('LOWER(email) <> ?', [self::PLATFORM_SUPERADMIN_EMAIL])
            ->whereRaw('LOWER(email) NOT LIKE ?', ['superadmin+%@plataforma.local']);
    }

    /** Transiciones permitidas (D-USER-FSM). */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE => [self::STATUS_SUSPENDED, self::STATUS_INACTIVE, self::STATUS_DELETED],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_DELETED],
        self::STATUS_INACTIVE => [self::STATUS_ACTIVE, self::STATUS_DELETED],
        self::STATUS_DELETED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'must_change_password',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'google_id',
        'google_email',
        'google_linked_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'temporary_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'google_linked_at' => 'datetime',
            // El secreto TOTP se cifra EN REPOSO (pendiente de Fase 0).
            'two_factor_secret' => 'encrypted',
        ];
    }

    /**
     * MFA TOTP activo (RN-RG-421 / D-MFA): el secreto ya fue confirmado por el
     * usuario. Un secreto guardado pero sin confirmar NO cuenta como habilitado.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * true cuando el usuario es el sombra del superadministrador (suplantacion
     * RN-RT-402). El superadmin SIEMPRE puede todo: los gates de permisos lo
     * dejan pasar (ver Gate::before en AppServiceProvider) y el frontend
     * expone `is_superadmin` para no bloquear la UI.
     */
    public function esSuperadminPlataforma(): bool
    {
        return self::isImpersonationShadowEmail((string) $this->email);
    }

    /**
     * Lee el secreto TOTP tolerante a datos legacy en texto plano.
     *
     * Antes del cifrado en reposo (comando `totp:encrypt-secrets`) podia haber
     * secretos guardados sin cifrar; el cast 'encrypted' lanzaria una excepcion
     * al descifrarlos. Aqui se degrada al valor crudo para no tumbar el login.
     */
    public function getTwoFactorSecret(): ?string
    {
        $value = $this->getRawOriginal('two_factor_secret');

        if ($value === null || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'eyJ')) {
            try {
                return (string) Crypt::decryptString($value);
            } catch (DecryptException) {
                return $value; // no es cifrado; se conserva el valor plano
            }
        }

        return $value;
    }

    /**
     * Aplica una transicion del ciclo de vida validando la FSM (D-USER-FSM).
     * Lanza InvalidArgumentException si la transicion no esta permitida.
     */
    public function transitionTo(string $newStatus): void
    {
        $current = $this->status;

        if ($current === $newStatus) {
            return;
        }

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException(
                "Transicion de estado invalida: '{$current}' → '{$newStatus}'."
            );
        }

        $this->update(['status' => $newStatus]);
    }

    /**
     * Transicion valida sin mutar (para validar antes de confirmar en la UI).
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
