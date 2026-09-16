<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\PlatformDataChanged;
use App\Events\TenantChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPlanManager;
use App\Services\TenantProvisioner;
use App\Support\Audit\AuditLogger;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gestion de colegios (tenants) desde el panel del superadministrador.
 * Rutas centrales (dominio de plataforma), protegidas por auth + platform.
 */
class ColegioController extends Controller
{
    use PaginatesRequests;

    /** Lista todos los colegios (las sedes/tenants hijo quedan fuera). */
    public function index(Request $request): JsonResponse
    {
        $result = Tenant::query()
            ->where('tipo', '!=', Tenant::TIPO_SEDE)
            ->orderByDesc('created_at')
            ->paginate($this->resolvePerPage($request))
            ->through(fn (Tenant $tenant) => $this->present($tenant));

        return $this->paginatedResponse($result);
    }

    /** Crea (provisiona) un colegio nuevo. */
    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'rector_email' => ['required', 'email'],
            'rector_name' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:60'],
            // El plan debe existir en la tabla de planes (los administra el superadmin).
            'plan' => ['nullable', 'string', 'exists:plans,key'],
        ]);

        try {
            ['tenant' => $tenant, 'password' => $password] = $provisioner->provision($data);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        PlatformDataChanged::dispatch('colegios', 'created');

        AuditLogger::platform(
            $request->user(),
            'CREATE',
            'colegio',
            (string) $tenant->id,
            null,
            [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ],
            null,
            (string) $tenant->id,
        );

        return response()->json([
            'colegio' => $this->present($tenant),
            // La contrasena temporal del rector se muestra UNA sola vez.
            'rector_password' => $password,
        ], 201)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /** Detalle de un colegio. */
    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    /** Edita los datos del colegio (nombre, razon social, NIT, plan). */
    public function update(Request $request, string $id, TenantPlanManager $planManager): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $before = [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'legal_name' => $tenant->legal_name,
            'nit' => $tenant->nit,
            'plan' => $tenant->plan,
        ];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:60'],
            'plan' => ['required', 'string', 'exists:plans,key'],
        ]);

        $slugChanged = ! empty($data['slug']) && $data['slug'] !== $tenant->slug;
        $nameChanged = $data['name'] !== $tenant->name;
        $planChanged = $tenant->plan !== $data['plan'];

        if ($slugChanged && Tenant::where('slug', $data['slug'])->where('id', '!=', $tenant->id)->exists()) {
            return response()->json(['message' => 'Ya existe un colegio con ese identificador.'], 422);
        }

        if ($planChanged) {
            try {
                $planManager->change($tenant, $data['plan']);
                $tenant->refresh();
            } catch (RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        // Guardamos el nombre de BD ACTUAL antes de cualquier cambio.
        $oldDbName = $tenant->getInternal('db_name') ?? TenantDatabaseName::for($tenant);

        if ($slugChanged) {
            $tenant->update(['slug' => $data['slug']]);
            $tenant->domains()->update(['domain' => $data['slug']]);

            foreach ($tenant->sedes as $sede) {
                $sede->domains()->update(['domain' => $sede->slug.'.'.$data['slug']]);
            }
        }

        $updateData = ['name' => $data['name']];
        if (isset($data['legal_name'])) {
            $updateData['legal_name'] = $data['legal_name'];
        }
        if (isset($data['nit'])) {
            $updateData['nit'] = $data['nit'];
        }

        $tenant->update($updateData);

        // Si cambio el nombre o el slug, la BD debe renombrarse.
        if ($slugChanged || $nameChanged) {
            $newDbName = TenantDatabaseName::for($tenant->fresh());
            if ($newDbName !== $oldDbName) {
                $this->renameDatabase($tenant, $oldDbName, $newDbName);
            }

            // Las sedes heredan el nombre del colegio en su DB naming.
            foreach ($tenant->sedes as $sede) {
                $oldSedeDb = $sede->getInternal('db_name') ?? TenantDatabaseName::for($sede);
                $newSedeDb = TenantDatabaseName::for($sede->fresh());
                if ($newSedeDb !== $oldSedeDb) {
                    $this->renameDatabase($sede, $oldSedeDb, $newSedeDb);
                }
            }
        }

        // TenantPlanManager ya sincronizo atomicamente colegio + sedes.
        if ($planChanged) {
            TenantChanged::dispatch($tenant->id, 'plan');
        }

        PlatformDataChanged::dispatch('colegios', 'updated');

        AuditLogger::platform(
            $request->user(),
            'UPDATE',
            'colegio',
            (string) $tenant->id,
            $before,
            [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'legal_name' => $tenant->legal_name,
                'nit' => $tenant->nit,
                'plan' => $tenant->plan,
            ],
            null,
            (string) $tenant->id,
        );

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    private function renameDatabase(Tenant $tenant, string $oldDbName, string $newDbName): void
    {
        $central = DB::connection(config('tenancy.database.central_connection'));

        if ($central->table('pg_database')->where('datname', $newDbName)->exists()) {
            return; // ya renombrada o colision, no hacer nada
        }

        // Cierra conexiones activas a la BD vieja.
        $central->statement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$oldDbName],
        );

        $central->statement('ALTER DATABASE "'.$oldDbName.'" RENAME TO "'.$newDbName.'"');

        // Persiste el nuevo nombre para futuros arranques.
        $tenant->setInternal('db_name', $newDbName)->save();
    }

    /** Cambia el estado del colegio (activar / suspender). */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);

        $prevStatus = $tenant->status;

        if ($tenant->tipo === Tenant::TIPO_SEDE || $tenant->parent_id !== null) {
            return response()->json([
                'message' => 'El estado de una sede se gestiona desde su colegio principal.',
            ], 422);
        }

        if ($prevStatus === $data['status']) {
            return response()->json(['colegio' => $this->present($tenant)]);
        }

        $allowed = ($prevStatus === Tenant::STATUS_ACTIVE && $data['status'] === Tenant::STATUS_SUSPENDED)
            || ($prevStatus === Tenant::STATUS_SUSPENDED && $data['status'] === Tenant::STATUS_ACTIVE);

        if (! $allowed) {
            return response()->json([
                'message' => 'Solo se puede suspender un colegio activo o reactivar uno suspendido. La activacion inicial depende de completar la configuracion minima.',
            ], 422);
        }

        $tenant->update(['status' => $data['status']]);

        // El rector conectado reacciona en vivo (expulsion si lo inhabilitaron).
        TenantChanged::dispatch($tenant->id, $data['status'] === 'suspended' ? 'disabled' : 'enabled');
        PlatformDataChanged::dispatch('colegios', $data['status'] === 'suspended' ? 'disabled' : 'enabled');

        AuditLogger::platform(
            $request->user(),
            $data['status'] === 'suspended' ? 'SUSPEND' : 'ENABLE',
            'colegio',
            (string) $tenant->id,
            ['status' => $prevStatus],
            ['status' => $tenant->status],
            null,
            (string) $tenant->id,
        );

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    /** Cambia el plan del colegio y re-sincroniza su RBAC (aplica el gating). */
    public function updatePlan(Request $request, string $id, TenantPlanManager $planManager): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,key'],
        ]);

        $prevPlan = $tenant->plan;
        try {
            $planManager->change($tenant, $data['plan']);
            $tenant->refresh();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        TenantChanged::dispatch($tenant->id, 'plan');
        PlatformDataChanged::dispatch('colegios', 'updated');

        AuditLogger::platform(
            $request->user(),
            'UPDATE',
            'colegio.plan',
            (string) $tenant->id,
            ['plan' => $prevPlan],
            ['plan' => $tenant->plan],
            null,
            (string) $tenant->id,
        );

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    /**
     * Regenera la contrasena temporal del rector y la devuelve UNA vez.
     * La clave solo existe en memoria durante esta solicitud: nunca se persiste
     * de forma reversible y las sesiones anteriores quedan revocadas.
     */
    public function resetRectorPassword(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $password = Str::password(14);
        $rectorEmail = null;
        $revokedTokens = 0;

        $tenant->run(function () use ($password, &$rectorEmail, &$revokedTokens): void {
            DB::transaction(function () use ($password, &$rectorEmail, &$revokedTokens): void {
                $rector = User::query()
                    ->withoutImpersonationShadows()
                    ->where('role', 'rector')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($rector === null) {
                    return;
                }

                $rector->update([
                    'password' => Hash::make($password),
                    'must_change_password' => true,
                ]);
                $revokedTokens = $rector->tokens()->delete();
                $rectorEmail = $rector->email;
            });
        });

        if ($rectorEmail === null) {
            return response()->json(['message' => 'El colegio no tiene un rector.'], 422);
        }

        AuditLogger::platform(
            $request->user(),
            'RESET_PASSWORD',
            'colegio.rector',
            (string) $tenant->id,
            null,
            ['rector_email' => $rectorEmail, 'tokens_revoked' => $revokedTokens],
            'Clave temporal regenerada; se entrega una sola vez y se revocan las sesiones anteriores.',
            (string) $tenant->id,
        );

        return response()->json([
            'colegio' => $this->present($tenant),
            'rector_email' => $rectorEmail,
            // Se muestra una sola vez.
            'rector_password' => $password,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'legal_name' => $tenant->legal_name,
            'nit' => $tenant->nit,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'subdomain' => $tenant->slug.'.'.config('tenancy.tenant_base_domain', 'localhost'),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
