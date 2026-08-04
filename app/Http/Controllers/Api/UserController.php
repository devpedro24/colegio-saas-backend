<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionMatrix;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion de usuarios del COLEGIO (permiso `usuarios.gestionar`).
 *
 * Los usuarios de la SEDE PRINCIPAL viven en la BD del colegio; los de una
 * sede ADICIONAL viven en la BD del tenant hijo (aislamiento por tenancy).
 * El listado consolida ambas, y al crear/editar se escribe en la BD correcta
 * con `runIn()` (switch de tenant sin anidar run() de Stancl).
 */
class UserController extends Controller
{
    /** Lista los usuarios del colegio + de cada sede (tenant hijo) activa. */
    public function index(): JsonResponse
    {
        $colegio = tenant();

        $principales = User::query()
            ->with('roles:id,name')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->serialize($u))
            ->values();

        $sedes = Sede::query()
            ->whereNotNull('tenant_id')
            ->orderBy('nombre')
            ->get();

        $deSedes = collect();
        foreach ($sedes as $sede) {
            $hijo = Tenant::find($sede->tenant_id);

            if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
                continue;
            }

            $usuarioSede = $this->runIn($hijo, function () use ($sede) {
                return User::query()
                    ->with('roles:id,name')
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $u) => $this->serialize($u, $sede))
                    ->values();
            });

            $deSedes = $deSedes->merge($usuarioSede);
        }

        return response()->json(['data' => $principales->merge($deSedes)->values()]);
    }

    /** Crea un usuario en el colegio o en la sede indicada. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request, crear: true);

        $sede = $this->sedeDestino($data['sede_id'] ?? null);
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if ($sede !== null) {
            $res = $this->crearEn($sede, $data);

            if (isset($res['error'])) {
                return response()->json(['message' => $res['error']], 422);
            }

            AuditLogger::tenant($request->user(), 'CREATE', 'usuario', $res['id'], null, $res);

            return response()->json([
                'data' => $res['data'],
                'password' => $res['password'],
            ], 201);
        }

        // Sede principal (BD del colegio, contexto actual).
        if (User::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Ya existe un usuario con ese correo.'], 422);
        }

        $password = $data['password'] ?? Str::password(12);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'role' => $data['role'],
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
        ]);
        $user->assignRole($data['role']);

        AuditLogger::tenant($request->user(), 'CREATE', 'usuario', (string) $user->id, null, $this->serialize($user));

        return response()->json([
            'data' => $this->serialize($user),
            'password' => $password,
        ], 201);
    }

    /** Edita datos, rol o estado de un usuario en su BD (cole'gio o sede). */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validar($request, crear: false);

        $sede = $this->sedeDestino($data['sede_id'] ?? null);
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if ($sede !== null) {
            return $this->actualizarEn($sede, $request->user(), $id, $data);
        }

        // BD del colegio (contexto actual).
        $usuario = User::findOrFail($id);

        if ($usuario->id === $request->user()->id && array_key_exists('role', $data)) {
            return response()->json(['message' => 'No puedes cambiar tu propio rol.'], 422);
        }

        $prev = $this->serialize($usuario);

        if (isset($data['status'])) {
            try {
                $usuario->transitionTo($data['status']);
            } catch (\InvalidArgumentException) {
                return response()->json(['message' => 'Transición de estado no permitida.'], 422);
            }
        }

        if (isset($data['password'])) {
            $usuario->update(['password' => $data['password'], 'must_change_password' => false]);
        }

        if (array_key_exists('name', $data)) {
            $usuario->update(['name' => $data['name']]);
        }

        if (array_key_exists('role', $data)) {
            $usuario->update(['role' => $data['role']]);
            $usuario->syncRoles([$data['role']]);
        }

        AuditLogger::tenant($request->user(), 'UPDATE', 'usuario', (string) $usuario->id, $prev, $this->serialize($usuario));

        return response()->json(['data' => $this->serialize($usuario)]);
    }

    /** Elimina (borrado logico) un usuario en su BD. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $sede = $this->sedeDestino($request->input('sede_id'));
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if ($sede !== null) {
            $res = $this->eliminarEn($sede, $request->user(), $id);

            if (isset($res['error'])) {
                return response()->json(['message' => $res['error']], 422);
            }

            return response()->json(['data' => null]);
        }

        $usuario = User::findOrFail($id);

        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $prev = $this->serialize($usuario);
        $usuario->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'usuario', (string) $usuario->id, $prev, null);

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $crear): array
    {
        return $request->validate([
            'name' => [Rule::when($crear, 'required'), 'string', 'max:120'],
            'email' => [Rule::when($crear, 'required'), 'email', 'max:255'],
            'role' => [Rule::when($crear, 'required'), Rule::in(PermissionMatrix::roleKeys())],
            'sede_id' => ['nullable', 'integer', 'exists:sedes,id'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_SUSPENDED])],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
    }

    /**
     * La sede destino del usuario, si alguna. Devuelve la Sede o un array de
     * error cuando la sede es principal (usa su BD) o no tiene tenant hijo.
     *
     * @return \App\Models\Academico\Sede|array{message:string}|null
     */
    private function sedeDestino(?int $sedeId)
    {
        if ($sedeId === null) {
            return null;
        }

        $sede = Sede::find($sedeId);

        if ($sede === null || $sede->tenant_id === null) {
            return ['message' => 'La sede indicada no es un tenant hijo.'];
        }

        $hijo = Tenant::find($sede->tenant_id);
        if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
            return ['message' => 'El tenant de la sede no está disponible.'];
        }

        return $sede;
    }

    /**
     * Cambia temporalmente al tenant hijo, ejecuta y restaura el colegio.
     * Evita anidar run() de Stancl.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    private function runIn(Tenant $hijo, Closure $fn)
    {
        $colegio = tenant();

        if (tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $hijo->id) {
            return $fn();
        }

        tenancy()->end();

        try {
            return $hijo->run($fn);
        } finally {
            tenancy()->initialize($colegio);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id:string, data:array<string, mixed>, password:string, error?:string}
     */
    private function crearEn(Sede $sede, array $data): array
    {
        $hijo = Tenant::find($sede->tenant_id);

        return $this->runIn($hijo, function () use ($data, $sede) {
            if (User::where('email', $data['email'])->exists()) {
                return ['error' => 'Ya existe un usuario con ese correo.'];
            }

            $password = $data['password'] ?? Str::password(12);
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $password,
                'role' => $data['role'],
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
            ]);
            $user->assignRole($data['role']);

            return [
                'id' => (string) $user->id,
                'data' => $this->serialize($user, $sede),
                'password' => $password,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function actualizarEn(Sede $sede, User $actor, int $id, array $data): JsonResponse
    {
        $hijo = Tenant::find($sede->tenant_id);

        $resultado = $this->runIn($hijo, function () use ($actor, $id, $data, $sede) {
            $usuario = User::findOrFail($id);
            $prev = $this->serialize($usuario, $sede);

            if (isset($data['status'])) {
                try {
                    $usuario->transitionTo($data['status']);
                } catch (\InvalidArgumentException) {
                    return ['error' => 'Transición de estado no permitida.'];
                }
            }

            if (isset($data['password'])) {
                $usuario->update(['password' => $data['password'], 'must_change_password' => false]);
            }

            if (array_key_exists('name', $data)) {
                $usuario->update(['name' => $data['name']]);
            }

            if (array_key_exists('role', $data)) {
                $usuario->update(['role' => $data['role']]);
                $usuario->syncRoles([$data['role']]);
            }

            return ['prev' => $prev, 'data' => $this->serialize($usuario, $sede)];
        });

        if (isset($resultado['error'])) {
            return response()->json(['message' => $resultado['error']], 422);
        }

        // El log de auditoria del COLEGIO registra la accion sobre la sede.
        AuditLogger::tenant($actor, 'UPDATE', 'usuario', (string) $id, $resultado['prev'], $resultado['data']);

        return response()->json(['data' => $resultado['data']]);
    }

    /**
     * @return array{error?:string}
     */
    private function eliminarEn(Sede $sede, User $actor, int $id): array
    {
        $hijo = Tenant::find($sede->tenant_id);

        return $this->runIn($hijo, function () use ($actor, $id) {
            $usuario = User::findOrFail($id);
            $prev = $this->serialize($usuario);
            $usuario->delete();

            AuditLogger::tenant($actor, 'DELETE', 'usuario', (string) $usuario->id, $prev, null);

            return [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user, ?Sede $sede = null): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'roles' => $user->roles->pluck('name')->values(),
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'created_at' => $user->created_at?->toISOString(),
            'sede_id' => $sede?->id,
            'sede_nombre' => $sede?->nombre,
            'tenant_id' => $sede?->tenant_id,
        ];
    }
}