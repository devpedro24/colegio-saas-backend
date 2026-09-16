<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\ModeloPedagogico;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sedes\SedeLimits;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Provisiona una SEDE como tenant hijo del colegio (CU-003):
 *
 * - Tenant central con `tipo = sede`, `parent_id = colegio` y su propia BD.
 * - Subdominio anidado `<slug>.<subdominio-colegio>` (p.ej. `norte.colegio-x`).
 * - RBAC del plan del colegio + sede principal propia + usuario coordinador.
 * - Opcionalmente hereda la configuracion del colegio (anos lectivos y
 *   periodos, escalas, metodos, modelos, datos institucionales, niveles y
 *   grados con remapeo de IDs). Jornadas, grupos y espacios NO se copian:
 *   cada sede los define de cero.
 */
class SedeProvisioner
{
    /**
     * @param  array{slug:string, name:string, coordinador_email:string, coordinador_name?:?string, heredar:bool}  $data
     * @param  array<string, mixed>|null  $snapshotPreCapturado  configuracion del colegio
     *                                                           capturada fuera (evita run() anidado de Stancl
     *                                                           cuando se llama desde dentro del tenant).
     * @return array{tenant: Tenant, password: string}
     */
    public function provision(Tenant $colegio, array $data, ?array $snapshotPreCapturado = null): array
    {
        if (User::isImpersonationShadowEmail($data['coordinador_email'] ?? null)) {
            throw new RuntimeException('El correo del coordinador pertenece a un namespace tecnico reservado.');
        }

        if ($colegio->tipo === Tenant::TIPO_SEDE || $colegio->parent_id !== null) {
            throw new RuntimeException('Una sede no puede crear sedes hijas.');
        }

        if (($violation = SedeLimits::centralViolation($colegio)) !== null) {
            throw new RuntimeException($violation);
        }

        $slug = Str::slug($data['slug']);

        $colegioDomain = $colegio->domains()->first()?->domain;
        if ($colegioDomain === null) {
            $colegioDomain = $colegio->slug;
        }

        $domain = $slug.'.'.$colegioDomain;

        if (Tenant::where('slug', $slug)->where('parent_id', $colegio->id)->exists()) {
            throw new RuntimeException("Ya existe una sede con el slug '{$slug}' en este colegio.");
        }

        if (DB::connection(config('tenancy.database.central_connection'))
            ->table('domains')->where('domain', $domain)->exists()) {
            throw new RuntimeException("El subdominio '{$domain}' ya esta en uso.");
        }

        // Snapshot de la configuracion del colegio ANTES de crear el hijo:
        // dentro de $tenant->run() la conexion seria la del hijo. Solo se
        // captura cuando herdar=true y no llega una captura hecha fuera.
        $snapshot = $snapshotPreCapturado;
        if ($snapshot === null && ($data['heredar'] ?? false)) {
            $snapshot = $this->capturarConfiguracion($colegio, true);
        }

        $tempPassword = Str::password(14);

        $sede = null;

        try {
            /** @var Tenant $sede */
            $sede = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'legal_name' => $colegio->legal_name,
                'nit' => $colegio->nit,
                'plan' => $colegio->plan,
                'status' => Tenant::STATUS_PROVISIONING,
                'tipo' => Tenant::TIPO_SEDE,
                'parent_id' => $colegio->id,
                'data' => ['parent_slug' => $colegio->slug],
            ]);

            $sede->domains()->create(['domain' => $domain]);

            $complete = $sede->run(function () use ($data, $tempPassword, $snapshot): bool {
                // Las reconstrucciones de tabla de SQLite ejecutan VACUUM y no
                // pueden vivir dentro de una transaccion. En PostgreSQL el
                // esquema se limpia antes de la transaccion de datos igualmente.
                $this->limpiarEsquemaSede();

                return DB::transaction(function () use ($data, $tempPassword, $snapshot): bool {
                    (new RbacSeeder(firstSeed: true))->run();

                    if (! empty($data['coordinador_email'])) {
                        $coordinador = User::create([
                            'name' => $data['coordinador_name'] ?? 'Coordinador',
                            'email' => $data['coordinador_email'],
                            'password' => Hash::make($tempPassword),
                            'role' => 'coord_combinado',
                            'status' => 'active',
                            'must_change_password' => true,
                        ]);

                        $coordinador->assignRole('coord_combinado');
                    }

                    if ($snapshot !== null) {
                        $this->applySnapshot($snapshot);
                    }

                    return ConfigurationGate::isComplete();
                });
            });

            // Nunca se marca activa por el solo hecho de crear la BD. Si no
            // heredo (o la captura estaba incompleta) permanece configuring.
            $sede->update([
                'status' => $complete ? Tenant::STATUS_ACTIVE : Tenant::STATUS_CONFIGURING,
            ]);
        } catch (Throwable $exception) {
            $this->discard($sede, $slug, $colegio->id, $exception);

            throw new RuntimeException(
                'No fue posible provisionar la sede. No se conservaron recursos parciales.',
                previous: $exception,
            );
        }

        return [
            'tenant' => $sede,
            'password' => ! empty($data['coordinador_email']) ? $tempPassword : null,
        ];
    }

    /**
     * Provisiona el tenant hijo y vincula la sede (ya creada en la BD del
     * colegio) al hijo mediante `tenant_id`.
     *
     * @param  array{slug:string, coordinador_email:string, coordinador_name?:?string, heredar:bool}  $data
     * @return array{tenant: Tenant, password: string}
     */
    public function provisionAndAttach(Tenant $colegio, Sede $sede, array $data, ?array $snapshotPreCapturado = null): array
    {
        $res = $this->provision($colegio, $data, $snapshotPreCapturado);

        try {
            $colegio->run(function () use ($sede, $res, $data): void {
                DB::transaction(function () use ($sede, $res, $data): void {
                    $attached = Sede::query()->whereKey($sede->getKey())->lockForUpdate()->firstOrFail();
                    $attached->update([
                        'tenant_id' => $res['tenant']->id,
                        'coordinador_email' => $data['coordinador_email'] ?? null,
                        'coordinador_name' => $data['coordinador_name'] ?? null,
                    ]);
                });
            });
        } catch (Throwable $exception) {
            $this->discard($res['tenant'], (string) $res['tenant']->slug, (string) $colegio->id, $exception);

            throw new RuntimeException('No fue posible vincular la sede al colegio.', previous: $exception);
        }

        return $res;
    }

    /**
     * Baja de una sede: se lleva al estado de cuarentena (patron colegios).
     * Se elimina el subdominio para que el acceso sea imposible, pero la BD
     * queda retenida para posible restauracion.
     *
     * Funciona tanto desde contexto central como dentro de un tenant: usa la
     * conexion central explicitamente.
     */
    public function bajarPorId(?string $sedeId): void
    {
        if ($sedeId === null) {
            return;
        }

        $central = DB::connection(config('tenancy.database.central_connection'));

        $central->table('tenants')
            ->where('id', $sedeId)
            ->update(['status' => Tenant::STATUS_IN_RETENTION]);

        $central->table('domains')
            ->where('tenant_id', $sedeId)
            ->delete();
    }

    /**
     * Elimina de la BD del tenant hijo todo lo relacionado con sedes: la tabla
     * `sedes` (solo vive en el colegio principal) y las columnas que la
     * referencian en la jerarquia organizacional. La BD recién migrada esta
     * vacia, asi que la operacion es segura.
     */
    public function limpiarEsquemaSede(): void
    {
        // SQLite no puede reconstruir una tabla mientras conserva un indice
        // que referencia la columna a eliminar. PostgreSQL acepta el DROP IF
        // EXISTS y luego elimina cualquier indice dependiente normalmente.
        foreach ([
            'jornadas_sede_id_nombre_unique',
            'espacios_fisicos_sede_id_nombre_unique',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        foreach (['jornadas', 'grupos', 'espacios_fisicos'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'sede_id')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['sede_id']);
            });
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('sede_id');
            });
        }

        if (Schema::hasTable('sedes')) {
            Schema::dropIfExists('sedes');
        }
    }

    /**
     * Captura la configuracion heredable del colegio. Detecta si ya estamos
     * dentro del contexto del colegio (rector) y evita un `run()` anidado de
     * Stancl; desde contexto central usa `$colegio->run()`.
     */
    public function capturarConfiguracion(Tenant $colegio, bool $heredar): ?array
    {
        if (! $heredar) {
            return null;
        }

        $yaEnContexto = tenancy()->initialized
            && tenancy()->tenant !== null
            && tenancy()->tenant->getTenantKey() === $colegio->id;

        if ($yaEnContexto) {
            return $this->capturar();
        }

        return $colegio->run(fn () => $this->capturar());
    }

    /**
     * @return array<string, mixed>
     */
    private function capturar(): array
    {
        return [
            'anos' => AnoLectivo::query()->with('periodos')->get()->toArray(),
            'escalas' => EscalaValorativa::all()->toArray(),
            'metodos' => MetodoAprobacion::all()->toArray(),
            'modelos' => ModeloPedagogico::all()->toArray(),
            'datos' => DatosInstitucionales::query()->first()?->toArray(),
            'niveles' => Nivel::query()->with('grados')->get()->toArray(),
        ];
    }

    /**
     * Inserta en la BD de la sede la configuracion heredada, remapeando los
     * IDs de anos lectivos y niveles hacia los registros nuevos.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function applySnapshot(array $snapshot): void
    {
        DB::transaction(fn () => $this->applySnapshotRows($snapshot));
    }

    /** @param array<string, mixed> $snapshot */
    private function applySnapshotRows(array $snapshot): void
    {
        $anoMap = [];
        if (isset($snapshot['anos'])) {
            foreach ($snapshot['anos'] as $ano) {
                $nuevo = AnoLectivo::firstOrCreate(
                    ['nombre' => $ano['nombre']],
                    [
                        'tipo_calendario' => $ano['tipo_calendario'],
                        'fecha_inicio' => $ano['fecha_inicio'],
                        'fecha_fin' => $ano['fecha_fin'],
                        'num_periodos' => $ano['num_periodos'],
                        'tiene_quinto_periodo' => $ano['tiene_quinto_periodo'],
                        'estado' => $ano['estado'],
                    ],
                );
                $anoMap[$ano['id']] = $nuevo->id;

                foreach ($ano['periodos'] ?? [] as $periodo) {
                    Periodo::firstOrCreate(
                        ['ano_lectivo_id' => $nuevo->id, 'nombre' => $periodo['nombre']],
                        [
                            'orden' => $periodo['orden'],
                            'fecha_inicio' => $periodo['fecha_inicio'],
                            'fecha_fin' => $periodo['fecha_fin'],
                            'peso' => $periodo['peso'],
                            'estado' => $periodo['estado'],
                        ],
                    );
                }
            }
        }

        if (isset($snapshot['escalas'])) {
            foreach ($snapshot['escalas'] as $escala) {
                if (! isset($anoMap[$escala['ano_lectivo_id']])) {
                    continue;
                }
                EscalaValorativa::firstOrCreate(
                    ['ano_lectivo_id' => $anoMap[$escala['ano_lectivo_id']], 'nombre' => $escala['nombre'], 'nivel_educativo' => $escala['nivel_educativo']],
                    ['tipo' => $escala['tipo'], 'valor_min' => $escala['valor_min'], 'valor_max' => $escala['valor_max'], 'decimales' => $escala['decimales']],
                );
            }
        }

        if (isset($snapshot['metodos'])) {
            foreach ($snapshot['metodos'] as $metodo) {
                if (! isset($anoMap[$metodo['ano_lectivo_id']])) {
                    continue;
                }
                MetodoAprobacion::firstOrCreate(
                    ['ano_lectivo_id' => $anoMap[$metodo['ano_lectivo_id']], 'ambito' => $metodo['ambito']],
                    ['calculo_nota' => $metodo['calculo_nota'], 'nota_minima' => $metodo['nota_minima']],
                );
            }
        }

        if (isset($snapshot['modelos'])) {
            foreach ($snapshot['modelos'] as $modelo) {
                if (! isset($anoMap[$modelo['ano_lectivo_id']])) {
                    continue;
                }
                ModeloPedagogico::firstOrCreate(
                    ['ano_lectivo_id' => $anoMap[$modelo['ano_lectivo_id']], 'nivel_educativo' => $modelo['nivel_educativo']],
                    ['docente_unico' => $modelo['docente_unico'], 'salon_fijo' => $modelo['salon_fijo'], 'tiene_director_grupo' => $modelo['tiene_director_grupo']],
                );
            }
        }

        if (isset($snapshot['datos']) && ($datos = $snapshot['datos']) !== null) {
            DatosInstitucionales::firstOrCreate(
                ['nombre' => $datos['nombre']],
                ['nit' => $datos['nit'], 'resolucion_men' => $datos['resolucion_men'], 'direccion' => $datos['direccion'], 'telefono' => $datos['telefono'], 'correo' => $datos['correo'], 'logo_principal' => null, 'logo_documentos' => null, 'isotipo' => null, 'colores' => $datos['colores']],
            );
        }

        $nivelMap = [];
        if (isset($snapshot['niveles'])) {
            foreach ($snapshot['niveles'] as $nivel) {
                $nuevoNivel = Nivel::firstOrCreate(
                    ['nivel_educativo' => $nivel['nivel_educativo'], 'nombre' => $nivel['nombre']],
                    ['orden' => $nivel['orden'], 'estado' => $nivel['estado']],
                );
                $nivelMap[$nivel['id']] = $nuevoNivel->id;

                foreach ($nivel['grados'] ?? [] as $grado) {
                    Grado::firstOrCreate(
                        ['nivel_id' => $nuevoNivel->id, 'codigo' => $grado['codigo']],
                        ['nombre' => $grado['nombre'], 'orden' => $grado['orden'], 'estado' => $grado['estado']],
                    );
                }
            }
        }
    }

    private function discard(?Tenant $sede, string $slug, string $parentId, Throwable $cause): void
    {
        try {
            $partial = $sede ?? Tenant::query()
                ->where('parent_id', $parentId)
                ->where('slug', $slug)
                ->first();

            if ($partial !== null) {
                $partial->domains()->delete();
                $partial->delete();
            }
        } catch (Throwable $cleanup) {
            Log::critical('Fallo la compensacion del provisioning de sede.', [
                'parent_id' => $parentId,
                'slug' => $slug,
                'cause' => $cause->getMessage(),
                'cleanup_error' => $cleanup->getMessage(),
            ]);
        }
    }
}
