<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Services\SedeProvisioner;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function __construct(private readonly SedeProvisioner $provisioner) {}

    /** Lista las sedes del colegio (principal primero). */
    public function index(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        // Se serializa DENTRO de $tenant->run(): al terminar, Stancl desconecta
        // la conexion del tenant y un modelo colgado no puede acceder a fechas.
        $sedes = $tenant->run(function () {
            return Sede::query()
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Sede $sede) => $this->snapshot($sede))
                ->values();
        });

        // Fuera del run: enriquece con los datos del tenant hijo (central).
        $sedes = $sedes->map(fn (array $sede) => $this->enrich($sede));

        return response()->json(['data' => $sedes]);
    }

    /** Crea una sede en el colegio (con tenant hijo si se indica slug). */
    public function store(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'coordinador_email' => ['nullable', 'email', 'max:255'],
            'coordinador_name' => ['nullable', 'string', 'max:120'],
            'heredar' => ['nullable', 'boolean'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        if (! empty($data['slug']) && empty($data['coordinador_email'])) {
            return response()->json([
                'message' => 'El coordinador de la sede es obligatorio (correo).',
            ], 422);
        }

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

            if ($data['es_principal'] ?? false) {
                Sede::query()->update(['es_principal' => false]);
            }

            $sede = Sede::create([
                'nombre' => $data['nombre'],
                'direccion' => $data['direccion'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'responsable' => $data['responsable'] ?? null,
                'coordinador_email' => $data['coordinador_email'] ?? null,
                'es_principal' => $data['es_principal'] ?? false,
                'estado' => $data['estado'] ?? Sede::ESTADO_ACTIVA,
            ]);
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $password = null;

        if (! empty($data['slug']) && ! ($data['es_principal'] ?? false)) {
            try {
                $res = $this->provisioner->provisionAndAttach($tenant, $sede, [
                    'slug' => $data['slug'],
                    'coordinador_email' => $data['coordinador_email'],
                    'coordinador_name' => $data['coordinador_name'] ?? null,
                    'heredar' => (bool) ($data['heredar'] ?? true),
                ]);
                $password = $res['password'];
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
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
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

            if ($data['es_principal'] ?? false) {
                Sede::query()->where('id', '!=', $sede->id)->update(['es_principal' => false]);
            }

            $sede->update([
                'nombre' => $data['nombre'],
                'direccion' => $data['direccion'] ?? $sede->direccion,
                'telefono' => $data['telefono'] ?? $sede->telefono,
                'responsable' => $data['responsable'] ?? $sede->responsable,
                'es_principal' => $data['es_principal'] ?? $sede->es_principal,
                'estado' => $data['estado'] ?? $sede->estado,
            ]);
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
            $sede->delete();
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
            'nombre' => $sede->nombre,
            'direccion' => $sede->direccion,
            'telefono' => $sede->telefono,
            'responsable' => $sede->responsable,
            'coordinador_email' => $sede->coordinador_email,
            'tenant_id' => $sede->tenant_id,
            'es_principal' => $sede->es_principal,
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
