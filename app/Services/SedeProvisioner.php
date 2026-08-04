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
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

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
     *                                    capturada fuera (evita run() anidado de Stancl
     *                                    cuando se llama desde dentro del tenant).
     * @return array{tenant: Tenant, password: string}
     */
    public function provision(Tenant $colegio, array $data, ?array $snapshotPreCapturado = null): array
    {
        $slug = Str::slug($data['slug']);

        $domain = $slug.'.'.$colegio->slug;

        if (Tenant::where('slug', $slug)->exists()) {
            throw new RuntimeException("Ya existe una sede con el slug '{$slug}'.");
        }

        if ($colegio->domains()->where('domain', $domain)->exists()) {
            throw new RuntimeException("El subdominio '{$domain}' ya esta en uso.");
        }

        // Snapshot de la configuracion del colegio ANTES de crear el hijo:
        // dentro de $tenant->run() la conexion seria la del hijo.
        $snapshot = $snapshotPreCapturado ?? $this->capturarConfiguracion($colegio, $data['heredar']);

        $tempPassword = Str::password(14);

        /** @var Tenant $sede */
        $sede = Tenant::create([
            'name' => $data['name'],
            'slug' => $slug,
            'legal_name' => $colegio->legal_name,
            'nit' => $colegio->nit,
            'plan' => $colegio->plan,
            'status' => Tenant::STATUS_CONFIGURING,
            'tipo' => Tenant::TIPO_SEDE,
            'parent_id' => $colegio->id,
            // Contrasena temporal del coordinador CIFRADA (cast 'encrypted'):
            // el superadmin puede verla mientras siga vigente.
            'rector_temporary_password' => $tempPassword,
            // El slug del padre en el JSON data: evita consultas extra en el
            // listado del panel central.
            'data' => ['parent_slug' => $colegio->slug],
        ]);

        // Subdominio anidado: <sede>.<colegio>. En dev se visita como
        // <sede>.<colegio>.localhost (middleware propio de identificacion).
        $sede->domains()->create(['domain' => $domain]);

        // Dentro de la BD de la sede: RBAC + sede principal + coordinador
        // + (opcional) configuracion heredada del colegio.
        $sede->run(function () use ($data, $tempPassword, $snapshot) {
            (new RbacSeeder(firstSeed: true))->run();

            Sede::create([
                'nombre' => $data['name'],
                'es_principal' => true,
            ]);

            $coordinador = User::create([
                'name' => $data['coordinador_name'] ?? 'Coordinador',
                'email' => $data['coordinador_email'],
                'password' => Hash::make($tempPassword),
                'role' => 'rector',
                'status' => 'active',
                'must_change_password' => true,
            ]);

            $coordinador->assignRole('rector');

            if ($snapshot !== null) {
                $this->applySnapshot($snapshot);
            }
        });

        // Provisioning sincrono y exitoso: la sede queda operativa (patron
        // ConfigurationGate del colegio principal).
        $sede->update(['status' => Tenant::STATUS_ACTIVE]);

        return ['tenant' => $sede, 'password' => $tempPassword];
    }

    /**
     * Provisiona el tenant hijo y vincula la sede (ya creada en la BD del
     * colegio) al hijo mediante `tenant_id`.
     *
     * @param  array{slug:string, coordinador_email:string, coordinador_name?:?string, heredar:bool}  $data
     * @return array{tenant: Tenant, password: string}
     */
    public function provisionAndAttach(Tenant $colegio, Sede $sede, array $data): array
    {
        $res = $this->provision($colegio, $data);

        $colegio->run(function () use ($sede, $res, $data) {
            $sede->update([
                'tenant_id' => $res['tenant']->id,
                'coordinador_email' => $data['coordinador_email'],
            ]);
        });

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
    protected function applySnapshot(array $snapshot): void
    {
        $anoMap = [];
        foreach ($snapshot['anos'] as $ano) {
            $nuevo = AnoLectivo::create([
                'nombre' => $ano['nombre'],
                'tipo_calendario' => $ano['tipo_calendario'],
                'fecha_inicio' => $ano['fecha_inicio'],
                'fecha_fin' => $ano['fecha_fin'],
                'num_periodos' => $ano['num_periodos'],
                'tiene_quinto_periodo' => $ano['tiene_quinto_periodo'],
                'estado' => $ano['estado'],
            ]);
            $anoMap[$ano['id']] = $nuevo->id;

            foreach ($ano['periodos'] ?? [] as $periodo) {
                Periodo::create([
                    'ano_lectivo_id' => $nuevo->id,
                    'nombre' => $periodo['nombre'],
                    'orden' => $periodo['orden'],
                    'fecha_inicio' => $periodo['fecha_inicio'],
                    'fecha_fin' => $periodo['fecha_fin'],
                    'peso' => $periodo['peso'],
                    'estado' => $periodo['estado'],
                ]);
            }
        }

        foreach ($snapshot['escalas'] as $escala) {
            EscalaValorativa::create([
                'ano_lectivo_id' => $anoMap[$escala['ano_lectivo_id']] ?? $escala['ano_lectivo_id'],
                'nivel_educativo' => $escala['nivel_educativo'],
                'nombre' => $escala['nombre'],
                'tipo' => $escala['tipo'],
                'valor_min' => $escala['valor_min'],
                'valor_max' => $escala['valor_max'],
                'decimales' => $escala['decimales'],
            ]);
        }

        foreach ($snapshot['metodos'] as $metodo) {
            MetodoAprobacion::create([
                'ano_lectivo_id' => $anoMap[$metodo['ano_lectivo_id']] ?? $metodo['ano_lectivo_id'],
                'calculo_nota' => $metodo['calculo_nota'],
                'nota_minima' => $metodo['nota_minima'],
                'ambito' => $metodo['ambito'],
            ]);
        }

        foreach ($snapshot['modelos'] as $modelo) {
            ModeloPedagogico::create([
                'ano_lectivo_id' => $anoMap[$modelo['ano_lectivo_id']] ?? $modelo['ano_lectivo_id'],
                'nivel_educativo' => $modelo['nivel_educativo'],
                'docente_unico' => $modelo['docente_unico'],
                'salon_fijo' => $modelo['salon_fijo'],
                'tiene_director_grupo' => $modelo['tiene_director_grupo'],
            ]);
        }

        if (($datos = $snapshot['datos']) !== null) {
            DatosInstitucionales::create([
                'nombre' => $datos['nombre'],
                'nit' => $datos['nit'],
                'resolucion_men' => $datos['resolucion_men'],
                'direccion' => $datos['direccion'],
                'telefono' => $datos['telefono'],
                'correo' => $datos['correo'],
                // Los logos/isotipo NO se copian: apuntarian al storage del
                // colegio (aislado por FilesystemTenancyBootstrapper); cada
                // sede sube los suyos.
                'logo_principal' => null,
                'logo_documentos' => null,
                'isotipo' => null,
                'colores' => $datos['colores'],
            ]);
        }

        $nivelMap = [];
        foreach ($snapshot['niveles'] as $nivel) {
            $nuevoNivel = Nivel::create([
                'nivel_educativo' => $nivel['nivel_educativo'],
                'nombre' => $nivel['nombre'],
                'orden' => $nivel['orden'],
                'estado' => $nivel['estado'],
            ]);
            $nivelMap[$nivel['id']] = $nuevoNivel->id;

            foreach ($nivel['grados'] ?? [] as $grado) {
                Grado::create([
                    'nivel_id' => $nuevoNivel->id,
                    'nombre' => $grado['nombre'],
                    'codigo' => $grado['codigo'],
                    'orden' => $grado['orden'],
                    'estado' => $grado['estado'],
                ]);
            }
        }
    }
}
