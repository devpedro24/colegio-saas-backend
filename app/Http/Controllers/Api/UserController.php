<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

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
    use PaginatesRequests;

    /** Lista los usuarios del colegio + de cada sede (tenant hijo) activa. */
    public function index(Request $request): JsonResponse
    {
        $usuarios = User::query()
            ->with('roles:id,name')
            ->withoutImpersonationShadows()
            ->whereNull('deleted_at')
            ->where('id', '!=', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->serialize($u));

        // La tabla `sedes` solo existe en la BD del colegio principal: dentro
        // de un tenant hijo no hay sub-sedes que consolidar.
        $sedes = Schema::hasTable('sedes')
            ? Sede::query()->whereNotNull('tenant_id')->orderBy('nombre')->get()
            : collect();

        foreach ($sedes as $sede) {
            $hijo = Tenant::find($sede->tenant_id);

            if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
                continue;
            }

            $usuarioSede = $this->runIn($hijo, function () use ($sede) {
                return User::query()
                    ->with('roles:id,name')
                    ->withoutImpersonationShadows()
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $u) => $this->serialize($u, $sede))
                    ->values()
                    ->toBase();
            });

            $usuarios = $usuarios->merge($usuarioSede);
        }

        // Pagina DESPUES de consolidar todas las BDs: total, pagina y
        // per_page describen exactamente la coleccion devuelta.
        $usuarios = $usuarios
            ->sortBy(fn (array $user): string => mb_strtolower((string) $user['name']))
            ->values();
        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $usuarios->forPage($page, $perPage)->values(),
            $usuarios->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $response = $this->paginatedResponse($paginator);
        $response->setData(array_merge($response->getData(true), [
            'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
        ]));

        return $response;
    }

    /** Crea un usuario en el colegio o en la sede indicada. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request, crear: true);

        $sede = $this->sedeDestino($data['sede_id'] ?? null);
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if (! $this->roleExistsIn($sede, $data['role'])) {
            return response()->json(['message' => 'El rol indicado no existe en la sede destino.'], 422);
        }

        if ($sede !== null) {
            $res = $this->crearEn($sede, $data);

            if (isset($res['error'])) {
                return response()->json(['message' => $res['error']], 422);
            }

            AuditLogger::tenant($request->user(), 'CREATE', 'usuario', $res['id'], null, $res['data']);

            $this->syncCoordinadorEmail($sede->id, $data['role'] ?? '', $data['email'] ?? null, $data['name'] ?? null);

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

        $this->syncCoordinadorEmail($sede?->id ?? null, $data['role'], $user->email);

        AuditLogger::tenant($request->user(), 'CREATE', 'usuario', (string) $user->id, null, $this->serialize($user));

        try {
            TenantDataChanged::dispatch('usuario', 'created', $user->name);
        } catch (\Throwable) {
        }

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

        if (isset($data['role']) && ! $this->roleExistsIn($sede, $data['role'])) {
            return response()->json(['message' => 'El rol indicado no existe en la sede destino.'], 422);
        }

        if ($sede !== null) {
            return $this->actualizarEn($sede, $request->user(), $id, $data);
        }

        // BD del colegio (contexto actual).
        $usuario = User::findOrFail($id);
        $this->ensureManageableUser($usuario);

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
            User::query()->whereKey($usuario->id)->update(['temporary_password' => null]);
            $usuario->tokens()->delete();
        }

        if (array_key_exists('name', $data)) {
            $usuario->update(['name' => $data['name']]);
        }

        if (array_key_exists('role', $data)) {
            $usuario->update(['role' => $data['role']]);
            $usuario->syncRoles([$data['role']]);
        }

        $this->syncCoordinadorEmail($data['sede_id'] ?? null, $data['role'] ?? $usuario->role, $usuario->email, $usuario->name);

        AuditLogger::tenant($request->user(), 'UPDATE', 'usuario', (string) $usuario->id, $prev, $this->serialize($usuario));

        try {
            TenantDataChanged::dispatch('usuario', 'updated', $usuario->name);
        } catch (\Throwable) {
        }

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
        $this->ensureManageableUser($usuario);

        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $prev = $this->serialize($usuario);
        $usuario->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'usuario', (string) $usuario->id, $prev, null);

        try {
            TenantDataChanged::dispatch('usuario', 'deleted', $usuario->name);
        } catch (\Throwable) {
        }

        return response()->json(['data' => null]);
    }

    /** POST /usuarios/{id}/reset-password — regenera la clave temporal. */
    public function resetPassword(Request $request, $id): JsonResponse
    {
        $sede = $this->sedeDestino($request->query('sede_id') !== null ? (int) $request->query('sede_id') : null);
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        $password = Str::password(12);
        $actor = $request->user();
        $reset = function () use ($id, $password, $actor, $sede): array {
            return DB::transaction(function () use ($id, $password, $actor, $sede): array {
                $user = User::query()->lockForUpdate()->findOrFail((int) $id);
                $this->ensureManageableUser($user);
                $previous = $this->serialize($user, $sede);
                $user->update(['password' => $password, 'must_change_password' => true]);
                User::query()->whereKey($user->id)->update(['temporary_password' => null]);
                $user->tokens()->delete();
                $data = $this->serialize($user->fresh('roles'), $sede);

                AuditLogger::tenant(
                    $actor,
                    'RESET_PASSWORD',
                    'usuario',
                    (string) $user->id,
                    $previous,
                    $data,
                    'Clave regenerada y sesiones anteriores revocadas.',
                );

                return $data;
            });
        };

        $data = $sede instanceof Sede
            ? $this->runIn(Tenant::findOrFail($sede->tenant_id), $reset)
            : $reset();

        return response()->json([
            'data' => $data,
            'password' => $password,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $crear): array
    {
        return $request->validate([
            'name' => [Rule::when($crear, 'required'), 'string', 'max:120'],
            'email' => [
                Rule::when($crear, 'required'),
                'email',
                'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::isImpersonationShadowEmail((string) $value)) {
                        $fail('El correo indicado pertenece a un namespace tecnico reservado.');
                    }
                },
            ],
            'role' => [Rule::when($crear, 'required'), 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'sede_id' => ['nullable', 'integer', 'exists:sedes,id'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_SUSPENDED])],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
    }

    /**
     * La sede destino del usuario, si alguna. Devuelve la Sede o un array de
     * error cuando la sede es principal (usa su BD) o no tiene tenant hijo.
     *
     * @return Sede|array{message:string}|null
     */
    private function sedeDestino(?int $sedeId)
    {
        if ($sedeId === null) {
            return null;
        }

        // En la BD de un tenant hijo (sede) no existe la tabla `sedes`: no hay
        // sub-sedes que elegir dentro de una sede.
        if (! Schema::hasTable('sedes')) {
            return ['message' => 'Esta sede no admite sub-sedes.'];
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
        $wasInitialized = tenancy()->initialized;

        if (tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $hijo->id) {
            return $fn();
        }

        tenancy()->end();

        try {
            return $hijo->run($fn);
        } finally {
            if ($wasInitialized && $colegio instanceof Tenant) {
                tenancy()->initialize($colegio);
            } else {
                tenancy()->end();
            }
        }
    }

    private function roleExistsIn(?Sede $sede, string $role): bool
    {
        if ($sede === null) {
            return Role::query()->where('name', $role)->where('guard_name', 'web')->exists();
        }

        $hijo = Tenant::find($sede->tenant_id);
        if ($hijo === null) {
            return false;
        }

        return $this->runIn(
            $hijo,
            fn (): bool => Role::query()->where('name', $role)->where('guard_name', 'web')->exists(),
        );
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

        $resultado = $this->runIn($hijo, function () use ($id, $data, $sede) {
            $usuario = User::findOrFail($id);
            $this->ensureManageableUser($usuario);
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
                User::query()->whereKey($usuario->id)->update(['temporary_password' => null]);
                $usuario->tokens()->delete();
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
            $this->ensureManageableUser($usuario);
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

    private function ensureManageableUser(User $user): void
    {
        if ($user->isImpersonationShadow()) {
            abort(404, 'Usuario no encontrado.');
        }
    }

    /**
     * Sincroniza `coordinador_email` en la tabla `sedes` cuando se asigna o
     * desasigna un usuario con rol de coordinacion a una sede.
     */
    private function syncCoordinadorEmail(?int $sedeId, string $role, ?string $email, ?string $name = null): void
    {
        if ($sedeId === null) {
            return;
        }

        if (! in_array($role, ['coord_combinado', 'coord_academico', 'coord_convivencia'], true)) {
            return;
        }

        // El listado de sedes muestra el ultimo coordinador asignado.
        if (Schema::hasTable('sedes')) {
            $update = ['coordinador_email' => $email];
            if ($name !== null) {
                $update['coordinador_name'] = $name;
            }
            Sede::where('id', $sedeId)->update($update);
        }
    }
}
