<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Services\SedeProvisioner;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Sedes de un colegio gestionadas por el SUPERADMIN desde el panel central.
 *
 * Las sedes viven en la BD del tenant; cada operacion se ejecuta dentro de
 * `$tenant->run()`. Aplica el mismo gating de plan (max_sedes) que el lado
 * del colegio. Toda operacion sensible queda en el log de auditoria de
 * plataforma (RN-AI-003).
 *
 * Una sede ADICIONAL (no principal) se provisiona como TENANT HIJO con su
 * propia BD, RBAC, usuarios y subdominio `<slug>.<subdominio-colegio>`
 * (SedeProvisioner). Al eliminar la sede, el tenant hijo baja a cuarentena
 * (estado in_retention, subdominio eliminado) liberando el cupo del plan.
 */
class ColegioSedeController extends Controller
{
    use PaginatesRequests;

    public function __construct(private readonly SedeProvisioner $provisioner) {}

    /** Lista las sedes del colegio (principal primero). */
    public function index(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        // Se serializa DENTRO de $tenant->run(): al terminar, Stancl desconecta
        // la conexion del tenant y un modelo colgado no puede acceder a fechas.
        $result = $tenant->run(function () use ($request) {
            return Sede::query()
                ->orderByRaw('tenant_id IS NULL DESC')
                ->orderBy('nombre')
                ->paginate($this->resolvePerPage($request))
                ->through(fn (Sede $sede) => $this->snapshot($sede));
        });

        // Fuera del run: enriquece con los datos del tenant hijo (central).
        $result->setCollection($result->getCollection()->map(fn (array $sede) => $this->enrich($sede)));

        return $this->paginatedResponse($result);
    }

    /** Crea una sede en el colegio (con tenant hijo si se indica slug). */
    public function store(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'direccion' => [Rule::when($request->isMethod('post'), 'required'), 'string', 'max:255'],
            'coordinador_email' => ['nullable', 'email', 'max:255'],
            'coordinador_name' => ['nullable', 'string', 'max:120'],
            'heredar' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);
        $error = null;
        $sede = null;
        $tenant->run(function () use ($data, &$sede, &$error) {
            if (SedeLimits::alLimite()) {
                $error = 'El plan del colegio permite máximo '.(string) SedeLimits::maxSedes().' sede(s).';

                return;
            }

            if (Sede::where('nombre', $data['nombre'])->exists()) {
                $error = 'Ya existe una sede con ese nombre.';

                return;
            }

            $sede = Sede::create([
                'nombre' => $data['nombre'],
                'direccion' => $data['direccion'] ?? null,
                'coordinador_name' => $data['coordinador_name'] ?? null,
                'coordinador_email' => $data['coordinador_email'] ?? null,
                'estado' => $data['estado'] ?? Sede::ESTADO_ACTIVA,
            ]);
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $password = null;

        if (! empty($data['slug'])) {
            try {
                $res = $this->provisioner->provisionAndAttach($tenant, $sede, [
                    'slug' => $data['slug'],
                    'name' => $data['nombre'],
                    'coordinador_email' => $data['coordinador_email'] ?? null,
                    'coordinador_name' => $data['coordinador_name'] ?? null,
                    'heredar' => false,
                ]);
                $password = $res['password'];
                try {
                    \App\Events\SedeCreada::dispatch((string) $sede->id, (string) $tenant->id);
                    TenantDataChanged::dispatch('sede', 'created', $data['nombre']);
                } catch (\Throwable $e) {
                    Log::warning('[WS] SedeCreada dispatch platform failed', ['error' => $e->getMessage()]);
                }
            } catch (RuntimeException $e) {
                // Rollback: la sede creada sin tenant no puede quedar huerfana.
                $tenant->run(fn () => $sede->forceDelete());

                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $snapshot = $this->enrich($this->snapshot($sede));

        AuditLogger::platform(
            $request->user(),
            'CREATE',
            'colegio.sede',
            (string) $tenant->id,
            null,
            $snapshot,
            null,
            (string) $tenant->id,
        );

        return response()->json([
            'data' => $snapshot,
            'coordinador_password' => $password,
        ], 201);
    }

    /** Edita una sede del colegio. */
    public function update(Request $request, string $id, int $sedeId): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        $error = null;
        $prev = null;
        $sede = null;
        $tenant->run(function () use ($data, $sedeId, &$prev, &$sede, &$error) {
            $sede = Sede::withTrashed()->find($sedeId);

            if (! $sede || $sede->trashed()) {
                $error = 'La sede no existe.';

                return;
            }

            if (Sede::where('nombre', $data['nombre'])->where('id', '!=', $sede->id)->exists()) {
                $error = 'Ya existe una sede con ese nombre.';

                return;
            }

            $prev = $this->snapshot($sede);

            $sede->update([
                'nombre' => $data['nombre'],
                'direccion' => $data['direccion'] ?? $sede->direccion,
                'estado' => $data['estado'] ?? $sede->estado,
            ]);

            try {
                TenantDataChanged::dispatch('sede', 'updated', $sede->nombre);
            } catch (\Throwable) {}
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        AuditLogger::platform(
            $request->user(),
            'UPDATE',
            'colegio.sede',
            (string) $tenant->id,
            $this->enrich($prev),
            $this->enrich($this->snapshot($sede)),
            null,
            (string) $tenant->id,
        );

        return response()->json(['data' => $this->enrich($this->snapshot($sede))]);
    }

    /** Elimina (soft-delete) una sede del colegio y baja su tenant hijo. */
    public function destroy(Request $request, string $id, int $sedeId): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $error = null;
        $prev = null;
        $tenantIdHijo = null;
        $tenant->run(function () use ($sedeId, &$prev, &$tenantIdHijo, &$error) {
            $sede = Sede::withTrashed()->find($sedeId);

            if (! $sede || $sede->trashed()) {
                $error = 'La sede no existe.';

                return;
            }

            $prev = $this->snapshot($sede);
            $tenantIdHijo = $sede->tenant_id;
            $nombre = $sede->nombre;
            $sede->delete();

            try {
                TenantDataChanged::dispatch('sede', 'deleted', $nombre);
            } catch (\Throwable) {}
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        // Cuarentena del tenant hijo: estado in_retention + subdominio eliminado.
        if ($tenantIdHijo !== null) {
            $this->provisioner->bajarPorId($tenantIdHijo);
        }

        AuditLogger::platform(
            $request->user(),
            'DELETE',
            'colegio.sede',
            (string) $tenant->id,
            $this->enrich($prev),
            null,
            null,
            (string) $tenant->id,
        );

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(?Sede $sede): array
    {
        if ($sede === null) {
            return [];
        }

        return [
            'id' => $sede->id,
            'hashed_id' => $sede->hashed_id,
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'telefono' => $sede->telefono,
            'coordinador_name' => $sede->coordinador_name,
            'coordinador_email' => $sede->coordinador_email,
            'tenant_id' => $sede->tenant_id,
            'estado' => $sede->estado,
        ];
    }

    /**
     * Adjunta los datos del tenant hijo (BD central) al snapshot.
     * Debe llamarse FUERA de `$tenant->run()` (conexion central).
     *
     * @param  array<string, mixed>  $sede
     * @return array<string, mixed>
     */
    private function enrich(array $sede): array
    {
        $tenantId = $sede['tenant_id'] ?? null;

        $sede['tenant_slug'] = null;
        $sede['tenant_domain'] = null;
        $sede['tenant_status'] = null;

        if ($tenantId !== null) {
            $hijo = Tenant::find($tenantId);

            if ($hijo !== null) {
                $sede['tenant_slug'] = $hijo->slug;
                $sede['tenant_domain'] = $hijo->domains()->first()?->domain;
                $sede['tenant_status'] = $hijo->status;
            }
        }

        return $sede;
    }
}
