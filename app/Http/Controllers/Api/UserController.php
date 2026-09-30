<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionMatrix;
use App\Support\PasswordPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
    use PaginatesRequests;

    /** Lista los usuarios del colegio + de cada sede (tenant hijo) activa. */
    public function index(Request $request): JsonResponse
    {
        $principales = User::query()
            ->whereNull('deleted_at')
            ->where('id', '!=', $request->user()->id)
            ->where('email', '!=', User::PLATFORM_SUPERADMIN_EMAIL);
        $principalCount = (clone $principales)->count();

        // La tabla `sedes` solo existe en la BD del colegio principal: dentro
        // de un tenant hijo no hay sub-sedes que consolidar.
        $sedes = Schema::hasTable('sedes')
            ? Sede::query()->whereNotNull('tenant_id')->orderBy('nombre')->get()
            : collect();

        $childSources = [];
        $total = $principalCount;
        foreach ($sedes as $sede) {
            $hijo = Tenant::find($sede->tenant_id);

            if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
                continue;
            }

            $count = $this->runIn($hijo, fn () => User::query()
                    ->whereNull('deleted_at')
                    ->where('email', '!=', User::PLATFORM_SUPERADMIN_EMAIL)
                    ->count());
            $childSources[] = [$sede, $hijo, $count, OpaqueUrlToken::for('sede', $sede->id)];
            $total += $count;
        }

        // Se conserva el orden histórico (principal y luego cada sede), pero
        // cada BD sólo devuelve la porción que cabe en la página solicitada.
        $smallList = $total <= 20;
        $perPage = $smallList ? 20 : $this->resolvePerPage($request);
        $page = $smallList ? 1 : $this->resolvePage($request);
        $offset = ($page - 1) * $perPage;
        $remaining = $perPage;
        $items = [];
        if ($offset >= $principalCount) {
            $offset -= $principalCount;
        } else {
            $rows = (clone $principales)->with('roles:id,name')->orderBy('name')
                ->offset($offset)->limit($remaining)->get();
            foreach ($rows as $user) {
                $items[] = $this->serialize($user);
            }
            $remaining -= $rows->count();
            $offset = 0;
        }

        foreach ($childSources as [$sede, $hijo, $count, $sedeToken]) {
            if ($remaining === 0) {
                break;
            }
            if ($offset >= $count) {
                $offset -= $count;
                continue;
            }
            $rows = $this->runIn($hijo, function () use ($sede, $sedeToken, $offset, $remaining) {
                return User::query()->with('roles:id,name')->whereNull('deleted_at')
                    ->where('email', '!=', User::PLATFORM_SUPERADMIN_EMAIL)
                    ->orderBy('name')->offset($offset)->limit($remaining)->get()
                    ->map(fn (User $user) => $this->serialize($user, $sede, $sedeToken))->all();
            });
            array_push($items, ...$rows);
            $remaining -= count($rows);
            $offset = 0;
        }

        return $this->paginatedResponse(new LengthAwarePaginator($items, $total, $perPage, $page));
    }

    /** Crea un usuario en el colegio o en la sede indicada. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request, crear: true);

        $sede = $this->sedeDestino($this->sedeSelector($data));
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if ($sede !== null) {
            $res = $this->crearEn($sede, $data);

            if (isset($res['error'])) {
                return response()->json(['message' => $res['error']], 422);
            }

            AuditLogger::tenant($request->user(), 'CREATE', 'usuario', $res['id'], null, $res);

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

        $password = $data['password'] ?? PasswordPolicy::temporary(12);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'role' => $data['role'],
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
            'temporary_password' => $password,
        ]);
        $user->assignRole($data['role']);

        $this->syncCoordinadorEmail($sede?->id ?? null, $data['role'], $user->email);

        AuditLogger::tenant($request->user(), 'CREATE', 'usuario', (string) $user->id, null, $this->serialize($user));

        try {
            TenantDataChanged::dispatch('usuario', 'created', $user->name);
        } catch (\Throwable) {}

        return response()->json([
            'data' => $this->serialize($user),
            'password' => $password,
        ], 201);
    }

    /** Edita datos, rol o estado de un usuario en su BD (cole'gio o sede). */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validar($request, crear: false);

        $sede = $this->sedeDestino($this->sedeSelector($data));
        if (is_array($sede)) {
            return response()->json(['message' => $sede['message']], 422);
        }

        if ($sede !== null) {
            return $this->actualizarEn($sede, $request->user(), $id, $data);
        }

        // BD del colegio (contexto actual).
        $usuario = $this->userForSelector($id);

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
            if ($usuario->status !== User::STATUS_ACTIVE) {
                $usuario->tokens()->delete();
            }
        }

        if (isset($data['password'])) {
            $usuario->update(['password' => $data['password'], 'must_change_password' => false]);
            $usuario->tokens()->delete();
        }

        if (array_key_exists('name', $data)) {
            $usuario->update(['name' => $data['name']]);
        }

        if (array_key_exists('role', $data)) {
            $usuario->update(['role' => $data['role']]);
            $usuario->syncRoles([$data['role']]);
        }

        $this->syncCoordinadorEmail($sede?->id, $data['role'] ?? $usuario->role, $usuario->email, $usuario->name);

        AuditLogger::tenant($request->user(), 'UPDATE', 'usuario', (string) $usuario->id, $prev, $this->serialize($usuario));

        try {
            TenantDataChanged::dispatch('usuario', 'updated', $usuario->name);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->serialize($usuario)]);
    }

    /** Elimina (borrado logico) un usuario en su BD. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $sede = $this->sedeDestino($this->sedeSelector($request->all()));
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

        $usuario = $this->userForSelector($id);

        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $prev = $this->serialize($usuario);
        $usuario->tokens()->delete();
        $usuario->delete();

        AuditLogger::tenant($request->user(), 'DELETE', 'usuario', (string) $usuario->id, $prev, null);

        try {
            TenantDataChanged::dispatch('usuario', 'deleted', $usuario->name);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
    }

    /**
     * Resuelve al usuario por ID, opcionalmente dentro de la BD de una sede
     * (`?sede_id=X`) usando runIn, y ejecuta el callback dentro del contexto
     * correcto. Devuelve el usuario + la contrasena generada.
     *
     * @return array{user: User, password: ?string, data: array<string, mixed>}
     */
    private function resolveAndUpdate(Request $request, string $id, bool $readOnly = false): array
    {
        $sede = $this->sedeDestino($this->sedeSelector($request->query()));
        if (is_array($sede)) {
            abort(422, $sede['message']);
        }
        $password = $readOnly ? '' : PasswordPolicy::temporary(12);

        if ($sede !== null) {
            $hijo = Tenant::findOrFail($sede->tenant_id);
            $sedeToken = OpaqueUrlToken::for('sede', $sede->id);
            $result = $this->runIn($hijo, function () use ($id, $password, $readOnly, $sede, $sedeToken) {
                $u = $this->userForSelector($id);
                if (! $readOnly) {
                    $u->update([
                        'password' => Hash::make($password),
                        'temporary_password' => $password,
                        'must_change_password' => true,
                    ]);
                    $u->tokens()->delete();
                }

                $fresh = $u->fresh();

                return ['user' => $fresh, 'data' => $this->serialize($fresh, $sede, $sedeToken)];
            });

            return [...$result, 'password' => $password ?: null];
        }

        $user = $this->userForSelector($id);
        if (! $readOnly) {
            $user->update([
                'password' => Hash::make($password),
                'temporary_password' => $password,
                'must_change_password' => true,
            ]);
            $user->tokens()->delete();
        }

        return ['user' => $user, 'data' => $this->serialize($user), 'password' => $password ?: null];
    }

    /** GET /usuarios/{id}/temporal-password — estado de la clave temporal. */
    public function temporalPassword(Request $request, $id): JsonResponse
    {
        ['user' => $user] = $this->resolveAndUpdate($request, $id, readOnly: true);

        if ($user->must_change_password && $user->temporary_password !== null) {
            return response()->json([
                'status' => 'temporal',
                'email' => $user->email,
                'password' => $user->temporary_password,
            ]);
        }

        if ($user->must_change_password) {
            return response()->json([
                'status' => 'none',
                'email' => $user->email,
                'password' => null,
            ]);
        }

        return response()->json([
            'status' => 'changed',
            'email' => $user->email,
            'password' => null,
        ]);
    }

    /** POST /usuarios/{id}/reset-password — regenera la clave temporal. */
    public function resetPassword(Request $request, $id): JsonResponse
    {
        ['data' => $data, 'password' => $password] = $this->resolveAndUpdate($request, $id);

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
            'email' => [Rule::when($crear, 'required'), 'email', 'max:255'],
            'role' => [Rule::when($crear, 'required'), Rule::in(PermissionMatrix::roleKeys())],
            'sede_id' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 128) {
                    $fail('La sede indicada no es válida.');
                }
            }],
            'sede_url_token' => ['nullable', 'string', 'size:24', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_SUSPENDED])],
            'password' => ['nullable', PasswordPolicy::rule()],
        ]);
    }

    /**
     * La sede destino del usuario, si alguna. Devuelve la Sede o un array de
     * error cuando la sede es principal (usa su BD) o no tiene tenant hijo.
     *
     * @return \App\Models\Academico\Sede|array{message:string}|null
     */
    private function sedeDestino(?string $sedeId)
    {
        if ($sedeId === null) {
            return null;
        }

        // En la BD de un tenant hijo (sede) no existe la tabla `sedes`: no hay
        // sub-sedes que elegir dentro de una sede.
        if (! Schema::hasTable('sedes')) {
            return ['message' => 'Esta sede no admite sub-sedes.'];
        }

        $sede = Sede::fromPublicId($sedeId);

        if ($sede === null || $sede->tenant_id === null) {
            return ['message' => 'La sede indicada no es un tenant hijo.'];
        }

        $hijo = Tenant::find($sede->tenant_id);
        if ($hijo === null || $hijo->status === Tenant::STATUS_IN_RETENTION) {
            return ['message' => 'El tenant de la sede no está disponible.'];
        }

        return $sede;
    }

    /** @param array<string, mixed> $data */
    private function sedeSelector(array $data): ?string
    {
        $value = $data['sede_url_token'] ?? $data['sede_id'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(is_string($value) || is_int($value), 422, 'La sede indicada no es válida.');
        $selector = (string) $value;
        abort_if(strlen($selector) > 128, 422, 'La sede indicada no es válida.');
        abort_if(ctype_digit($selector) && ! app()->environment('testing'), 422, 'La sede indicada no es válida.');

        return $selector;
    }

    private function userForSelector(string $selector): User
    {
        if (ctype_digit($selector)) {
            abort_unless(app()->environment('testing'), 404, 'Usuario no encontrado.');

            return User::findOrFail((int) $selector);
        }

        $user = OpaqueUrlToken::find('usuario', $selector, User::query());
        abort_unless($user instanceof User, 404, 'Usuario no encontrado.');

        return $user;
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
        $sedeToken = OpaqueUrlToken::for('sede', $sede->id);

        return $this->runIn($hijo, function () use ($data, $sede, $sedeToken) {
            if (User::where('email', $data['email'])->exists()) {
                return ['error' => 'Ya existe un usuario con ese correo.'];
            }

            $password = $data['password'] ?? PasswordPolicy::temporary(12);
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $password,
                'role' => $data['role'],
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
                'temporary_password' => $password,
            ]);
            $user->assignRole($data['role']);

            return [
                'id' => (string) $user->id,
                'data' => $this->serialize($user, $sede, $sedeToken),
                'password' => $password,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function actualizarEn(Sede $sede, User $actor, string $id, array $data): JsonResponse
    {
        $hijo = Tenant::find($sede->tenant_id);
        $sedeToken = OpaqueUrlToken::for('sede', $sede->id);

        $resultado = $this->runIn($hijo, function () use ($actor, $id, $data, $sede, $sedeToken) {
            $usuario = $this->userForSelector($id);
            $prev = $this->serialize($usuario, $sede, $sedeToken);

            if (isset($data['status'])) {
                try {
                    $usuario->transitionTo($data['status']);
                } catch (\InvalidArgumentException) {
                    return ['error' => 'Transición de estado no permitida.'];
                }
                if ($usuario->status !== User::STATUS_ACTIVE) {
                    $usuario->tokens()->delete();
                }
            }

            if (isset($data['password'])) {
                $usuario->update(['password' => $data['password'], 'must_change_password' => false]);
                $usuario->tokens()->delete();
            }

            if (array_key_exists('name', $data)) {
                $usuario->update(['name' => $data['name']]);
            }

            if (array_key_exists('role', $data)) {
                $usuario->update(['role' => $data['role']]);
                $usuario->syncRoles([$data['role']]);
            }

            return ['internal_id' => (string) $usuario->id, 'prev' => $prev,
                'data' => $this->serialize($usuario, $sede, $sedeToken)];
        });

        if (isset($resultado['error'])) {
            return response()->json(['message' => $resultado['error']], 422);
        }

        // El log de auditoria del COLEGIO registra la accion sobre la sede.
        AuditLogger::tenant($actor, 'UPDATE', 'usuario', $resultado['internal_id'], $resultado['prev'], $resultado['data']);

        return response()->json(['data' => $resultado['data']]);
    }

    /**
     * @return array{error?:string}
     */
    private function eliminarEn(Sede $sede, User $actor, string $id): array
    {
        $hijo = Tenant::find($sede->tenant_id);

        return $this->runIn($hijo, function () use ($actor, $id) {
            $usuario = $this->userForSelector($id);
            $prev = $this->serialize($usuario);
            $usuario->tokens()->delete();
            $usuario->delete();

            AuditLogger::tenant($actor, 'DELETE', 'usuario', (string) $usuario->id, $prev, null);

            return [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user, ?Sede $sede = null, ?string $sedeToken = null): array
    {
        $userToken = OpaqueUrlToken::for('usuario', $user->id);

        return [
            'id' => $userToken,
            'url_token' => $userToken,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'roles' => $user->roles->pluck('name')->values(),
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'created_at' => $user->created_at?->toISOString(),
            'sede_id' => $sedeToken,
            'sede_url_token' => $sedeToken,
            'sede_nombre' => $sede?->nombre,
            // Legacy key kept until the UI switches to sede_url_token.
            'tenant_id' => $sedeToken,
        ];
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
