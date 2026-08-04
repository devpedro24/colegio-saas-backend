<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\PlatformDataChanged;
use App\Events\TenantChanged;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Audit\AuditLogger;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gestion de colegios (tenants) desde el panel del superadministrador.
 * Rutas centrales (dominio de plataforma), protegidas por auth + platform.
 */
class ColegioController extends Controller
{
    /** Lista todos los colegios. */
    public function index(): JsonResponse
    {
        $colegios = Tenant::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Tenant $tenant) => $this->present($tenant));

        return response()->json(['data' => $colegios]);
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
        ], 201);
    }

    /** Detalle de un colegio. */
    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    /** Edita los datos del colegio (nombre, razon social, NIT, plan). */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:60'],
            'plan' => ['required', 'string', 'exists:plans,key'],
        ]);

        $prev = [
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'nit' => $tenant->nit,
            'plan' => $tenant->plan,
        ];

        $planChanged = $tenant->plan !== $data['plan'];
        $tenant->update($data);

        // Si cambio el plan, re-sincroniza el RBAC del colegio (aplica el gating).
        if ($planChanged) {
            $tenant->run(fn () => (new RbacSeeder())->run());
            TenantChanged::dispatch($tenant->id, 'plan');
        }

        PlatformDataChanged::dispatch('colegios', 'updated');

        AuditLogger::platform(
            $request->user(),
            'UPDATE',
            'colegio',
            (string) $tenant->id,
            $prev,
            [
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name,
                'nit' => $tenant->nit,
                'plan' => $tenant->plan,
            ],
            null,
            (string) $tenant->id,
        );

        return response()->json(['colegio' => $this->present($tenant)]);
    }

    /** Cambia el estado del colegio (activar / suspender). */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);

        $prevStatus = $tenant->status;
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
    public function updatePlan(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,key'],
        ]);

        $prevPlan = $tenant->plan;
        $tenant->update(['plan' => $data['plan']]);

        // Re-siembra spatie del colegio con el nuevo plan (preserva configurables del rector).
        $tenant->run(fn () => (new RbacSeeder())->run());

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
     * Consulta (sin invalidar) la contraseña temporal vigente del rector.
     *
     * - 'temporal': el rector aun no la cambio y hay una guardada cifrada ->
     *               se descifra y devuelve (solo al superadmin del panel).
     * - 'changed' : el rector ya inicio sesion y cambio su clave -> la temporal
     *               ya no funciona; el panel debe ofrecer reestablecerla.
     * - 'none'    : no hay clave guardada (colegios anteriores a la columna) o
     *               cayo en una situacion no recuperable; se regenara una nueva.
     */
    public function rectorPassword(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $rectorEmail = null;
        $mustChange = false;
        $tenant->run(function () use (&$rectorEmail, &$mustChange) {
            $rector = User::where('role', 'rector')->orderBy('id')->first();
            if ($rector) {
                $rectorEmail = $rector->email;
                $mustChange = (bool) $rector->must_change_password;
            }
        });

        if ($rectorEmail === null) {
            return response()->json([
                'status' => 'none',
                'rector_email' => null,
                'rector_password' => null,
            ]);
        }

        $stored = $tenant->rector_temporary_password;

        if ($mustChange && $stored !== null) {
            return response()->json([
                'status' => 'temporal',
                'rector_email' => $rectorEmail,
                'rector_password' => $stored,
            ]);
        }

        return response()->json([
            'status' => $mustChange ? 'none' : 'changed',
            'rector_email' => $rectorEmail,
            'rector_password' => null,
        ]);
    }

    /**
     * Regenera la contrasena temporal del rector y la devuelve UNA vez.
     * (Las contrasenas se guardan cifradas: no se puede recuperar la anterior,
     * por eso se genera una nueva.)
     */
    public function resetRectorPassword(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $password = Str::password(14);
        $rectorEmail = null;

        $tenant->run(function () use ($password, &$rectorEmail) {
            $rector = User::where('role', 'rector')->orderBy('id')->first();
            if ($rector) {
                $rector->update([
                    'password' => Hash::make($password),
                    'must_change_password' => true,
                ]);
                $rectorEmail = $rector->email;
            }
        });

        if ($rectorEmail === null) {
            return response()->json(['message' => 'El colegio no tiene un rector.'], 422);
        }

        // Guarda la nueva temporal CIFRADA para poder mostrarla mientras vija.
        $tenant->update(['rector_temporary_password' => $password]);

        return response()->json([
            'colegio' => $this->present($tenant),
            'rector_email' => $rectorEmail,
            // Se muestra una sola vez.
            'rector_password' => $password,
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
            'subdomain' => $tenant->slug.'.localhost',
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
