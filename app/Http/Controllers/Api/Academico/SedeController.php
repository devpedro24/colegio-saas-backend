<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\ConfiguracionHeredada;
use App\Events\SedeCreada;
use App\Events\TenantDataChanged;
use App\Http\Controllers\Api\Platform\ContactSettingController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContactNumbers;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Services\SedeCapacity;
use App\Services\SedeConfiguration;
use App\Services\SedeInheritance;
use App\Services\SedeProvisioner;
use App\Support\AcademicOpaqueRecord;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Sedes del colegio (jerarquía Bloque B). Permiso `academico.estructura.gestionar`.
 *
 * Una sede ADICIONAL (no principal) se provisiona como TENANT HIJO con su
 * propia BD, RBAC, coordinador y subdominio `<slug>.<subdominio-colegio>`
 * (SedeProvisioner). Al eliminar la sede, el tenant hijo baja a cuarentena.
 */
class SedeController extends Controller
{
    use PaginatesRequests;

    public function __construct(private readonly SedeProvisioner $provisioner) {}

    /** Lista las sedes (principal primero). */
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('sedes')) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1,
                'per_page' => $this->resolvePerPage($request), 'total' => 0, 'from' => null, 'to' => null]]);
        }

        $result = $this->paginateAcademic(Sede::query()
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            ->orderByRaw('tenant_id IS NULL DESC')
            ->orderBy('nombre')
            ->orderBy('id'), $request)
            ->through(fn (Sede $sede) => $this->present($sede));

        return $this->paginatedResponse($result);
    }

    public function options(Request $request): JsonResponse
    {
        abort_if(tenant()->parent_id, 403);
        $sede = $request->filled('sede_token') ? $this->findSede($request->query('sede_token')) : null;
        $coordinators = User::where('status', 'active')
            ->whereIn('role', SedeConfiguration::COORDINATORS)->orderBy('name')->get();

        return response()->json([
            'capacidad' => SedeCapacity::summary($sede),
            'contacto' => ContactSettingController::contact(),
            'colegio' => tenant()->name,
            'celular_pais' => ContactNumbers::countryForTimezone(tenant()->zonaHorariaInstitucional()),
            'matriculados' => $sede ? SedeCapacity::enrolled($sede) : 0,
            'coordinadores' => $coordinators->map(fn ($user) => ['url_token' => OpaqueUrlToken::for('usuario', $user->id),
                'nombre' => $user->name, 'correo' => $user->email, 'rol' => $user->role]),
            'departamentos' => EnrollmentSubmission::geography(),
            'dominio' => SedeConfiguration::domainHost(tenant()),
            'dependencias' => SedeInheritance::DEPENDENCIES,
        ]);
    }

    /** Detalle de una sede. */
    public function show($id): JsonResponse
    {
        $sede = $this->findSede($id);

        return response()->json(['data' => $this->present($sede)]);
    }

    /** Crea una sede (con tenant hijo si se indica slug). */
    public function store(Request $request): JsonResponse
    {
        // Gating SaaS: el contrato limita las sedes, incluida la principal.
        if (SedeLimits::alLimite()) {
            return response()->json([
                'message' => 'El plan del colegio permite máximo '.(string) SedeLimits::maxSedes().' sede(s).',
            ], 422);
        }

        $data = app(SedeConfiguration::class)->validate($request);
        $snapshot = ($data['heredar'] ?? false)
            ? app(SedeInheritance::class)->capture($data['categorias'] ?? []) : null;
        $sede = SedeLimits::create(collect($data)->only((new Sede)->getFillable())->all());
        $password = null;
        $colegio = tenant();
        try {
            tenancy()->end();
            try {
                $res = $this->provisioner->provision($colegio, [
                    'slug' => $data['slug'], 'name' => $data['nombre'],
                    'heredar' => false, 'selected_configuration' => true,
                ], $snapshot);
            } finally {
                tenancy()->initialize($colegio);
            }
            $sede->update(['tenant_id' => $res['tenant']->id]);
        } catch (\Throwable $error) {
            $sede->forceDelete();
            throw $error;
        }

        AuditLogger::tenant($request->user(), 'CREATE', 'sede', (string) $sede->id, null, $this->enrich($this->snapshot($sede)));

        try {
            TenantDataChanged::dispatch('sede', 'created', $data['nombre']);
        } catch (\Throwable) {
        }

        if ($sede->tenant_id !== null) {
            try {
                SedeCreada::dispatch((string) $sede->id, (string) tenant()->id);
            } catch (\Throwable $e) {
                Log::warning('[WS] SedeCreada dispatch failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'data' => $this->present($sede),
            'coordinador_password' => $password,
        ], 201);
    }

    /** Edita una sede. */
    public function update(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);

        $data = app(SedeConfiguration::class)->validate($request, $sede);
        $prev = $this->enrich($this->snapshot($sede));
        $snapshot = ($data['heredar'] ?? false)
            ? app(SedeInheritance::class)->capture($data['categorias'] ?? []) : null;
        if ($snapshot && ! $sede->tenant_id) {
            abort(422, 'La configuración se hereda desde la principal hacia una sede adicional.');
        }
        $central = DB::connection(config('tenancy.database.central_connection'));
        $central->transaction(function () use ($central, $sede, $data, $snapshot) {
            $central->table('tenants')->where('id', tenant()->id)->lockForUpdate()->first();
            SedeCapacity::validate($data['capacidad_maxima'], $sede);
            if ($snapshot) {
                Tenant::findOrFail($sede->tenant_id)->run(fn () => app(SedeInheritance::class)->apply($snapshot));
            }
            DB::transaction(function () use ($sede, $data) {
                $sede->update(collect($data)->only($sede->getFillable())->all());
                app(SedeConfiguration::class)->synchronize($sede, $data);
            });
        });

        AuditLogger::tenant($request->user(), 'UPDATE', 'sede', (string) $sede->id, $prev, $this->enrich($this->snapshot($sede)));

        try {
            TenantDataChanged::dispatch('sede', 'updated', $sede->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($sede)]);
    }

    /** Elimina (soft-delete) una sede y baja su tenant hijo a cuarentena. */
    public function destroy(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);
        $prev = $this->enrich($this->snapshot($sede));

        abort_if(! $sede->tenant_id, 422, 'La sede principal se conserva durante la vida del colegio.');
        $sede->delete();

        if ($sede->tenant_id !== null) {
            $this->provisioner->bajarPorId($sede->tenant_id);
        }

        AuditLogger::tenant($request->user(), 'DELETE', 'sede', (string) $sede->id, $prev, null);

        try {
            TenantDataChanged::dispatch('sede', 'deleted', $sede->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => null]);
    }

    /** POST heredar — copia la configuracion seleccionada del colegio a una sede ya existente. */
    public function heredar(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);

        if ($sede->tenant_id === null) {
            return response()->json(['message' => 'Esta sede no tiene un tenant hijo.'], 422);
        }

        $hijo = Tenant::find($sede->tenant_id);
        if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
            return response()->json(['message' => 'El tenant de la sede no esta disponible.'], 422);
        }

        $data = $request->validate(['categorias' => ['required', 'array'],
            'categorias.*' => ['string', Rule::in(SedeInheritance::CATEGORIES)]]);
        $snapshot = app(SedeInheritance::class)->capture($data['categorias']);
        $hijo->run(fn () => app(SedeInheritance::class)->apply($snapshot));

        $result = $this->enrich($this->snapshot($sede));
        AuditLogger::tenant($request->user(), 'HEREDAR', 'sede', (string) $sede->id, null, $result);

        // Broadcast para actualizar el frontend en tiempo real
        try {
            ConfiguracionHeredada::dispatch($hijo->id, (string) tenant()->id);
        } catch (\Throwable $e) {
            Log::warning('[WS] ConfiguracionHeredada dispatch failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['data' => $request->boolean('opaque')
            ? AcademicOpaqueRecord::present($result, 'sede') : $result]);
    }

    /** @return array<string, mixed> */
    private function present(Sede $sede): array
    {
        $data = $this->enrich($this->snapshot($sede));

        return request()->boolean('opaque') ? AcademicOpaqueRecord::present($data, 'sede') : $data;
    }

    private function findSede($id): Sede
    {
        if (request()->boolean('opaque')) {
            return OpaqueUrlToken::find('sede', (string) $id, Sede::query()) ?? abort(404);
        }

        return Sede::fromPublicId((string) $id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Sede $sede): array
    {
        return [
            'id' => $sede->id,
            'hashed_id' => $sede->hashed_id,
            'url_token' => $sede->hashed_id,
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'telefono' => $sede->telefono,
            'departamento' => $sede->departamento,
            'municipio' => $sede->municipio,
            'capacidad_maxima' => $sede->capacidad_maxima,
            'coordinador_token' => $sede->coordinador_id ? OpaqueUrlToken::for('usuario', $sede->coordinador_id) : null,
            'coordinador_name' => $sede->coordinador_name,
            'coordinador_email' => $sede->coordinador_email,
            'tenant_id' => $sede->tenant_id,
            'estado' => $sede->estado,
        ];
    }

    /**
     * Adjunta los datos del tenant hijo (BD central) al snapshot.
     * Se llama dentro del contexto del colegio: usa la conexion central
     * explicitamente.
     *
     * Tambien obtiene los coordinadores reales de la tabla `users`: el campo
     * `coordinador_email` de la sede se actualiza desde la asignacion via
     * UserController (crear/editar usuario con sede + rol coordinador), pero
     * aqui tambien se refleja para los casos en que la tabla `sedes` aun no
     * se sincronizo.
     *
     * @param  array<string, mixed>  $sede
     * @return array<string, mixed>
     */
    private function enrich(array $sede): array
    {
        $sede['tenant_slug'] = null;
        $sede['tenant_domain'] = null;
        $sede['tenant_status'] = null;

        $tenantId = $sede['tenant_id'] ?? null;
        if ($tenantId === null) {
            $sede['tenant_domain'] = SedeConfiguration::domainHost(tenant());
            $sede['principal'] = true;
            $sede['slug'] = tenant()->slug;

            return $sede;
        }
        $sede['principal'] = false;

        $central = DB::connection(config('tenancy.database.central_connection'));

        $hijo = $central->table('tenants')->where('id', $tenantId)->first();
        if ($hijo !== null) {
            $sede['tenant_slug'] = $hijo->slug;
            $sede['slug'] = $hijo->slug;
            $sede['tenant_status'] = $hijo->status;
        }

        $dominio = $central->table('domains')->where('tenant_id', $tenantId)->first();
        if ($dominio !== null) {
            $sede['tenant_domain'] = SedeConfiguration::domainHost(Tenant::findOrFail($tenantId));
        }

        return $sede;
    }
}
