<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\User;
use App\Services\SedeProvisioner;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

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
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 5, 'total' => 0]]);
        }

        $result = Sede::query()
            ->orderByRaw('tenant_id IS NULL DESC')
            ->orderBy('nombre')
            ->paginate($this->resolvePerPage($request))
            ->through(fn (Sede $sede) => $this->enrich($this->snapshot($sede)));

        return $this->paginatedResponse($result);
    }

    /** Detalle de una sede. */
    public function show($id): JsonResponse
    {
        $sede = $this->findSede($id);

        return response()->json(['data' => $this->enrich($this->snapshot($sede))]);
    }

    /** Crea una sede (con tenant hijo si se indica slug). */
    public function store(Request $request): JsonResponse
    {
        // Gating SaaS: el plan limita la cantidad de sedes (esencial=1, estandar=5).
        if (SedeLimits::alLimite()) {
            return response()->json([
                'message' => 'El plan del colegio permite máximo '.(string) SedeLimits::maxSedes().' sede(s).',
            ], 422);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('sedes', 'nombre')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'coordinador_email' => ['nullable', 'email', 'max:255'],
            'coordinador_name' => ['nullable', 'string', 'max:120'],
            'heredar' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        $sede = Sede::create([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'coordinador_name' => $data['coordinador_name'] ?? null,
            'coordinador_email' => $data['coordinador_email'] ?? null,
            'estado' => $data['estado'] ?? Sede::ESTADO_ACTIVA,
        ]);

        $password = null;

        if (! empty($data['slug'])) {
            try {
                $colegio = tenant();

                // Snapshot dentro del contexto actual del colegio y provision
                // del hijo DESDE contexto central (evita run() anidado).
                $snapshot = $this->provisioner->capturarConfiguracion(
                    $colegio,
                    (bool) ($data['heredar'] ?? true),
                );

                tenancy()->end();

                try {
                    $res = $this->provisioner->provision($colegio, [
                        'slug' => $data['slug'],
                        'name' => $data['nombre'],
                        'coordinador_email' => $data['coordinador_email'] ?? null,
                        'coordinador_name' => $data['coordinador_name'] ?? null,
                        'heredar' => (bool) ($data['heredar'] ?? true),
                    ], $snapshot);
                    $password = $res['password'];
                } finally {
                    tenancy()->initialize($colegio);
                }

$sede->update([
                    'tenant_id' => $res['tenant']->id,
                    'coordinador_email' => $data['coordinador_email'] ?? null,
                    'coordinador_name' => $data['coordinador_name'] ?? null,
                ]);
            } catch (RuntimeException $e) {
                // Rollback: la sede no puede quedar huerfana sin tenant.
                $sede->forceDelete();

                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        AuditLogger::tenant($request->user(), 'CREATE', 'sede', (string) $sede->id, null, $this->enrich($this->snapshot($sede)));

        try {
            TenantDataChanged::dispatch('sede', 'created', $data['nombre']);
        } catch (\Throwable) {}

        if ($sede->tenant_id !== null) {
            try {
                \App\Events\SedeCreada::dispatch((string) $sede->id, (string) tenant()->id);
            } catch (\Throwable $e) {
                Log::warning('[WS] SedeCreada dispatch failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'data' => $this->enrich($this->snapshot($sede)),
            'coordinador_password' => $password,
        ], 201);
    }

    /** Edita una sede. */
    public function update(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('sedes', 'nombre')->ignore($sede->id)->where(fn ($q) => $q->whereNull('deleted_at'))],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        $prev = $this->enrich($this->snapshot($sede));
        $sede->update([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? $sede->direccion,
            'telefono' => $data['telefono'] ?? $sede->telefono,
            'estado' => $data['estado'] ?? $sede->estado,
        ]);

        AuditLogger::tenant($request->user(), 'UPDATE', 'sede', (string) $sede->id, $prev, $this->enrich($this->snapshot($sede)));

        try {
            TenantDataChanged::dispatch('sede', 'updated', $sede->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->enrich($this->snapshot($sede))]);
    }

    /** Elimina (soft-delete) una sede y baja su tenant hijo a cuarentena. */
    public function destroy(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);
        $prev = $this->enrich($this->snapshot($sede));

        $sede->delete();

        if ($sede->tenant_id !== null) {
            $this->provisioner->bajarPorId($sede->tenant_id);
        }

        AuditLogger::tenant($request->user(), 'DELETE', 'sede', (string) $sede->id, $prev, null);

        try {
            TenantDataChanged::dispatch('sede', 'deleted', $sede->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /** POST heredar — copia la configuracion seleccionada del colegio a una sede ya existente. */
    public function heredar(Request $request, $id): JsonResponse
    {
        $sede = $this->findSede($id);

        if ($sede->tenant_id === null) {
            return response()->json(['message' => 'Esta sede no tiene un tenant hijo.'], 422);
        }

        $hijo = \App\Models\Tenant::find($sede->tenant_id);
        if ($hijo === null || $hijo->status === \App\Models\Tenant::STATUS_IN_RETENTION) {
            return response()->json(['message' => 'El tenant de la sede no esta disponible.'], 422);
        }

        $categorias = $request->input('categorias', ['anos', 'escalas', 'metodos', 'modelos', 'datos', 'niveles']);

        $snapshot = $this->provisioner->capturarConfiguracion(tenant(), true);
        if ($snapshot === null) {
            return response()->json(['message' => 'No se pudo capturar la configuracion del colegio.'], 500);
        }

        // Solo aplica las categorias seleccionadas
        $filtrado = array_intersect_key($snapshot, array_flip($categorias));
        if (empty($filtrado)) {
            return response()->json(['message' => 'No se selecciono ninguna categoria.'], 422);
        }

        $hijo->run(fn () => $this->provisioner->applySnapshot($filtrado));

        $result = $this->enrich($this->snapshot($sede));
        AuditLogger::tenant($request->user(), 'HEREDAR', 'sede', (string) $sede->id, null, $result);

        // Broadcast para actualizar el frontend en tiempo real
        try {
            \App\Events\ConfiguracionHeredada::dispatch($hijo->id, (string) tenant()->id);
        } catch (\Throwable $e) {
            Log::warning('[WS] ConfiguracionHeredada dispatch failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['data' => $result]);
    }

    private function findSede($id): Sede
    {
        if (is_numeric($id)) {
            return Sede::findOrFail((int) $id);
        }

        // hashed_id base64url sin padding
        $decoded = base64_decode(
            strtr((string) $id, '-_', '+/') . str_repeat('=', (4 - strlen((string) $id) % 4) % 4),
            true,
        );

        if ($decoded !== false && is_numeric($decoded)) {
            return Sede::findOrFail((int) $decoded);
        }

        return Sede::findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Sede $sede): array
    {
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
            return $sede;
        }

        $central = DB::connection(config('tenancy.database.central_connection'));

        $hijo = $central->table('tenants')->where('id', $tenantId)->first();
        if ($hijo !== null) {
            $sede['tenant_slug'] = $hijo->slug;
            $sede['tenant_status'] = $hijo->status;
        }

        $dominio = $central->table('domains')->where('tenant_id', $tenantId)->first();
        if ($dominio !== null) {
            $sede['tenant_domain'] = $dominio->domain;
        }

        return $sede;
    }
}