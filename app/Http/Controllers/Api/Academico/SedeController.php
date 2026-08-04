<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\Sede;
use App\Services\SedeProvisioner;
use App\Support\Audit\AuditLogger;
use App\Support\Sedes\SedeLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly SedeProvisioner $provisioner) {}

    /** Lista las sedes (principal primero). */
    public function index(): JsonResponse
    {
        $sedes = Sede::query()
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Sede $sede) => $this->enrich($this->snapshot($sede)))
            ->values();

        return response()->json(['data' => $sedes]);
    }

    /** Detalle de una sede. */
    public function show(int $id): JsonResponse
    {
        $sede = Sede::findOrFail($id);

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
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        if (! empty($data['slug']) && empty($data['coordinador_email'])) {
            return response()->json([
                'message' => 'El coordinador de la sede es obligatorio (correo).',
            ], 422);
        }

        // Solo una sede puede ser principal a la vez.
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
                        'coordinador_email' => $data['coordinador_email'],
                        'coordinador_name' => $data['coordinador_name'] ?? null,
                        'heredar' => true, // El snapshot ya fue capturado (o null).
                    ], $snapshot);
                    $password = $res['password'];
                } finally {
                    tenancy()->initialize($colegio);
                }

                $sede->update([
                    'tenant_id' => $res['tenant']->id,
                    'coordinador_email' => $data['coordinador_email'],
                ]);
            } catch (RuntimeException $e) {
                // Rollback: la sede no puede quedar huerfana sin tenant.
                $sede->forceDelete();

                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        AuditLogger::tenant($request->user(), 'CREATE', 'sede', (string) $sede->id, null, $this->enrich($this->snapshot($sede)));

        return response()->json([
            'data' => $this->enrich($this->snapshot($sede)),
            'coordinador_password' => $password,
        ], 201);
    }

    /** Edita una sede. */
    public function update(Request $request, int $id): JsonResponse
    {
        $sede = Sede::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('sedes', 'nombre')->ignore($sede->id)->where(fn ($q) => $q->whereNull('deleted_at'))],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'responsable' => ['nullable', 'string', 'max:120'],
            'es_principal' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in([Sede::ESTADO_ACTIVA, Sede::ESTADO_INACTIVA])],
        ]);

        if ($data['es_principal'] ?? false) {
            Sede::query()->where('id', '!=', $sede->id)->update(['es_principal' => false]);
        }

        $prev = $this->enrich($this->snapshot($sede));
        $sede->update([
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'] ?? $sede->direccion,
            'telefono' => $data['telefono'] ?? $sede->telefono,
            'responsable' => $data['responsable'] ?? $sede->responsable,
            'es_principal' => $data['es_principal'] ?? $sede->es_principal,
            'estado' => $data['estado'] ?? $sede->estado,
        ]);

        AuditLogger::tenant($request->user(), 'UPDATE', 'sede', (string) $sede->id, $prev, $this->enrich($this->snapshot($sede)));

        return response()->json(['data' => $this->enrich($this->snapshot($sede))]);
    }

    /** Elimina (soft-delete) una sede y baja su tenant hijo a cuarentena. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $sede = Sede::findOrFail($id);
        $prev = $this->enrich($this->snapshot($sede));

        $sede->delete();

        if ($sede->tenant_id !== null) {
            $this->provisioner->bajarPorId($sede->tenant_id);
        }

        AuditLogger::tenant($request->user(), 'DELETE', 'sede', (string) $sede->id, $prev, null);

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Sede $sede): array
    {
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
     * Se llama dentro del contexto del colegio: usa la conexion central
     * explicitamente.
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