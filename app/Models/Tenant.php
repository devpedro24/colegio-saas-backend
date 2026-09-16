<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant = Colegio.
 *
 * Cada colegio es un tenant aislado con su propia base de datos PostgreSQL
 * (RN-AI-001) y su propio subdominio `<slug>.<dominio>` (RN-AU-001).
 *
 * - `id`   : clave inmutable corta para tenants nuevos; UUID solo en registros
 *            legacy. Se usa en logs, backups e integraciones.
 * - `slug` : identificador legible que forma el subdominio; mutable solo por
 *            el superadministrador y con cuarentena al reutilizarse (RN-MT-240).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $plan
 * @property string $status
 * @property string $calendar
 * @property string $locale
 * @property string $timezone
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * Maquina de estados del tenant (RN-T-001..004).
     * El tenant nace en PROVISIONING, pasa a CONFIGURING mientras el rector
     * completa la configuracion minima obligatoria, y a ACTIVE cuando opera.
     */
    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_CONFIGURING = 'configuring';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLATION_REQUESTED = 'cancellation_requested';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_IN_RETENTION = 'in_retention';

    public const STATUS_DELETED = 'deleted';

    /** Planes comerciales por numero de estudiantes (bandas). */
    public const PLAN_ESENCIAL = 'esencial';   // < 300 estudiantes

    public const PLAN_ESTANDAR = 'estandar';   // 300 - 800

    public const PLAN_PREMIUM = 'premium';     // 800 +

    /** Tipo de tenant: colegio principal o sede (tenant hijo). */
    public const TIPO_COLEGIO = 'colegio';

    public const TIPO_SEDE = 'sede';

    /**
     * Columnas reales de la tabla `tenants` (el resto vive en la columna JSON `data`).
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'legal_name',
            'nit',
            'plan',
            'status',
            'tipo',
            'parent_id',
            'calendar',
            'locale',
            'timezone',
        ];
    }

    /** Tenant padre (el colegio) cuando este tenant es una sede. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'parent_id', 'id');
    }

    /** Sedes de este colegio (tenants hijos). */
    public function sedes(): HasMany
    {
        return $this->hasMany(Tenant::class, 'parent_id', 'id');
    }
}
