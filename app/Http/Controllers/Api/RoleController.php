<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\Sede;
use App\Models\CustomRole;
use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SchoolRoleSynchronizer;
use App\Services\TenantPlanAccess;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Matriz de permisos del colegio (RBAC de 3 capas) para el rector.
 *
 * La estructura (roles, permisos, clasificacion) viene del catalogo CENTRAL
 * (editable por el superadmin), filtrada por el PLAN del colegio (gating). El
 * estado real de los configurables vive en spatie (BD del tenant). El backend
 * es la AUTORIDAD: rechaza tocar celdas estructurales, denegadas o bloqueadas
 * por el plan.
 */
class RoleController extends Controller
{
    use PaginatesRequests;

    private const RECTOR_ONLY = ['usuarios.ajustar_permisos'];

    /** Devuelve la matriz efectiva del colegio: roles x permisos por modulo. */
    public function index(Request $request): JsonResponse
    {
        $this->requireRector($request);
        $access = app(TenantPlanAccess::class);
        $planFeatures = $access->features();

        // Catalogo central.
        $rbacRoles = RbacRole::orderBy('sort_order')->orderBy('id')->get();
        $permissionsPaginator = RbacPermission::orderBy('sort_order')->orderBy('id')
            ->paginate($this->resolvePerPage($request));
        $matrix = RbacMatrixCell::all()->groupBy('role_key');

        // Estado real de grants en el tenant.
        $dbRoles = Role::with('permissions:id,name')->get()->keyBy('name');

        $roles = $rbacRoles->map(fn (RbacRole $r) => ['key' => $r->key, 'label' => $r->label,
            'custom' => false, 'locked_by_plan' => false])->values();
        $customRoles = CustomRole::query()->orderBy('created_at')->orderBy('key')->get();
        foreach ($customRoles as $role) {
            $roles->push(['key' => $role->key, 'label' => $role->label, 'custom' => true,
                'locked_by_plan' => ! $access->customRoleIsEffective($role->key)]);
        }

        $modules = [];
        foreach ($permissionsPaginator as $perm) {
            $feature = $perm->feature_key;
            $planAllowed = $feature === null || in_array($feature, $planFeatures, true);

            $cells = [];
            foreach ($rbacRoles as $rbacRole) {
                $roleKey = $rbacRole->key;
                $cell = ($matrix[$roleKey] ?? collect())->firstWhere('permission_key', $perm->key);
                $type = $cell->type ?? 'denied';
                $lockedByPlan = $type !== 'denied' && ! $planAllowed;

                $granted = match (true) {
                    $lockedByPlan => false,
                    $type === 'structural' => true,
                    $type === 'configurable' => (bool) ($dbRoles[$roleKey]?->permissions->contains('name', $perm->key) ?? false),
                    default => false,
                };

                $cells[$roleKey] = [
                    'type' => $type,                                  // structural | configurable | denied
                    'level' => $cell->level ?? null,
                    'granted' => $granted,
                    'locked_by_plan' => $lockedByPlan,
                    'required_feature' => $lockedByPlan ? $feature : null,
                ];
            }
            foreach ($customRoles as $customRole) {
                $key = $customRole->key;
                $eligible = ! in_array($perm->key, self::RECTOR_ONLY, true);
                $locked = $eligible && (! $planAllowed || ! $access->customRoleIsEffective($key));
                $cells[$key] = [
                    'type' => $eligible ? 'configurable' : 'denied',
                    'level' => null,
                    'granted' => $eligible && ! $locked
                        && (bool) ($dbRoles[$key]?->permissions->contains('name', $perm->key) ?? false),
                    'locked_by_plan' => $locked,
                    'required_feature' => ! $planAllowed ? $feature : null,
                ];
            }

            $modules[$perm->module][] = [
                'key' => $perm->key,
                'action' => $perm->action,
                'feature_key' => $feature,
                'cells' => $cells,
            ];
        }

        $moduleList = [];
        foreach ($modules as $moduleName => $permissionList) {
            $moduleList[] = ['module' => $moduleName, 'permissions' => $permissionList];
        }

        $paginator = new LengthAwarePaginator(
            $moduleList,
            $permissionsPaginator->total(),
            $permissionsPaginator->perPage(),
            $permissionsPaginator->currentPage(),
        );

        $response = $this->paginatedResponse($paginator);
        $response->setData(array_merge(
            $response->getData(true),
            ['roles' => $roles, 'custom_role_limit' => $access->customRoleLimit(),
                'custom_role_count' => $customRoles->count(), 'plan_features' => $planFeatures],
        ));

        return $response;
    }

    /** Activa/desactiva un permiso CONFIGURABLE (y permitido por el plan) para un rol. */
    public function togglePermission(Request $request, string $role, string $permission): JsonResponse
    {
        $this->requireRector($request);
        $custom = CustomRole::where('key', $role)->first();
        if (! $custom && ! RbacRole::where('key', $role)->exists()) {
            abort(404, 'Rol no encontrado.');
        }

        $perm = RbacPermission::where('key', $permission)->first();
        if (! $perm) {
            abort(404, 'Permiso no encontrado.');
        }

        $cell = RbacMatrixCell::where('role_key', $role)
            ->where('permission_key', $permission)
            ->first();

        // AUTORIDAD DEL BACKEND: solo celdas configurables.
        if ($custom && in_array($permission, self::RECTOR_ONLY, true)) {
            abort(422, 'Este permiso está reservado al rector.');
        }
        if (! $custom && (! $cell || $cell->type !== 'configurable')) {
            abort(422, 'Este permiso no es configurable para este rol (estructural o no permitido).');
        }
        if ($custom && ! app(TenantPlanAccess::class)->customRoleIsEffective($role)) {
            abort(422, 'Este rol adicional supera el límite del plan actual.');
        }

        // Gating: bloqueado si el plan no incluye su feature.
        $planAllowed = $perm->feature_key === null || in_array($perm->feature_key, $this->planFeatures(), true);
        if (! $planAllowed) {
            abort(422, 'Este permiso no esta disponible en el plan actual del colegio.');
        }

        $granted = $request->validate([
            'granted' => ['required', 'boolean'],
        ])['granted'];

        // Asegura que exista la fila de spatie (por si el catalogo cambio sin sync).
        Permission::findOrCreate($permission, 'web');
        $roleModel = Role::findByName($role, 'web');

        if ($granted) {
            $roleModel->givePermissionTo($permission);
        } else {
            $roleModel->revokePermissionTo($permission);
        }

        app(TenantPlanAccess::class)->forget();
        app(SchoolRoleSynchronizer::class)->synchronize($role);
        AuditLogger::tenant($request->user(), 'UPDATE', 'rol_permiso', $role.':'.$permission,
            null, ['granted' => $granted]);

        return response()->json([
            'role' => $role,
            'permission' => $permission,
            'granted' => $granted,
        ]);
    }

    /** Crea un rol adicional, sin cambiar el rol académico principal de nadie. */
    public function storeCustomRole(Request $request): JsonResponse
    {
        $this->requireRector($request);
        $data = $request->validate(['label' => ['required', 'string', 'min:2', 'max:120']]);
        $access = app(TenantPlanAccess::class);
        $key = DB::transaction(function () use ($data, $access): string {
            // Serializa las altas del colegio para que dos solicitudes simultáneas
            // no consuman el último cupo al mismo tiempo.
            Role::query()->where('name', 'rector')->lockForUpdate()->firstOrFail();
            $limit = $access->customRoleLimit();
            abort_if($limit !== null && CustomRole::count() >= $limit, 422,
                'El plan actual no permite crear más roles adicionales.');

            $stem = substr(Str::slug($data['label'], '_'), 0, 49);
            $stem = $stem !== '' ? $stem : 'rol';
            $key = 'custom_'.$stem;
            for ($suffix = 2; CustomRole::whereKey($key)->exists() || Role::where('name', $key)->exists(); $suffix++) {
                $key = 'custom_'.substr($stem, 0, 49 - strlen((string) $suffix)).'_'.$suffix;
            }
            CustomRole::create(['key' => $key, 'label' => trim($data['label']), 'is_active' => true]);
            Role::findOrCreate($key, 'web');

            return $key;
        });
        $access->forget();
        app(SchoolRoleSynchronizer::class)->synchronize($key);
        AuditLogger::tenant($request->user(), 'CREATE', 'rol_adicional', $key,
            null, ['label' => trim($data['label'])]);

        return response()->json(['role' => ['key' => $key, 'label' => trim($data['label']),
            'custom' => true, 'locked_by_plan' => false]], 201);
    }

    public function updateCustomRole(Request $request, string $role): JsonResponse
    {
        $this->requireRector($request);
        $custom = CustomRole::whereKey($role)->firstOrFail();
        $data = $request->validate(['label' => ['required', 'string', 'min:2', 'max:120']]);
        $before = ['label' => $custom->label];
        $custom->update(['label' => trim($data['label'])]);
        app(SchoolRoleSynchronizer::class)->synchronize($role);
        AuditLogger::tenant($request->user(), 'UPDATE', 'rol_adicional', $role,
            $before, ['label' => $custom->label]);

        return response()->json(['role' => ['key' => $role, 'label' => $custom->label,
            'custom' => true, 'locked_by_plan' => ! app(TenantPlanAccess::class)->customRoleIsEffective($role)]]);
    }

    /** Un rol con usuarios asociados se conserva; no borramos sus asignaciones implícitamente. */
    public function destroyCustomRole(Request $request, string $role): JsonResponse
    {
        $this->requireRector($request);
        $custom = CustomRole::whereKey($role)->firstOrFail();
        $roleModel = Role::findByName($role, 'web');
        abort_if(DB::table('model_has_roles')->where('role_id', $roleModel->getKey())->exists(), 422,
            'Retira primero este rol de sus usuarios.');
        abort_if(app(SchoolRoleSynchronizer::class)->hasChildAssignments($role), 422,
            'Retira primero este rol de sus usuarios en las sedes.');

        DB::transaction(function () use ($custom, $roleModel): void {
            $roleModel->delete();
            $custom->delete();
        });
        app(TenantPlanAccess::class)->forget();
        app(SchoolRoleSynchronizer::class)->synchronize($role);
        AuditLogger::tenant($request->user(), 'DELETE', 'rol_adicional', $role,
            ['label' => $custom->label], null);

        return response()->json(['deleted' => true]);
    }

    /** Asigna complementos, preservando el rol principal que gobierna el alcance académico. */
    public function assignCustomRoles(Request $request, string $usuario): JsonResponse
    {
        $this->requireRector($request);
        $data = $request->validate(['roles' => ['present', 'array', 'max:100'],
            'sede_url_token' => ['nullable', 'string', 'size:24', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'roles.*' => ['string', 'distinct', Rule::exists('custom_roles', 'key')]]);
        $access = app(TenantPlanAccess::class);
        $allowed = $access->effectiveCustomRoleKeys();
        $child = null;
        if (! empty($data['sede_url_token'])) {
            $sede = Sede::fromPublicId($data['sede_url_token']);
            abort_unless($sede?->tenant_id, 404);
            $child = Tenant::find($sede->tenant_id);
            abort_unless($child && $child->parent_id === tenant()->getTenantKey()
                && ! in_array($child->status, [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION], true), 404);
        }

        $assign = function () use ($usuario, $data, $allowed): array {
            $user = ctype_digit($usuario) && app()->environment('testing')
                ? User::find($usuario)
                : OpaqueUrlToken::find('usuario', $usuario, User::query());
            abort_unless($user instanceof User && $user->email !== User::PLATFORM_SUPERADMIN_EMAIL, 404);
            abort_if($user->role === 'estudiante', 422, 'No se pueden asignar roles administrativos a estudiantes.');

            $previous = $user->getRoleNames()->filter(fn (string $key) => str_starts_with($key, 'custom_'))->values()->all();
            foreach ($data['roles'] as $key) {
                abort_unless(in_array($key, $allowed, true) || in_array($key, $previous, true), 422,
                    'Uno de los roles adicionales no está disponible en el plan actual.');
            }

            $baseRoles = $user->getRoleNames()->reject(fn (string $key) => str_starts_with($key, 'custom_'))->all();
            $user->syncRoles(array_values(array_unique([...$baseRoles, ...$data['roles']])));

            return ['previous' => $previous, 'user_id' => (string) $user->getKey()];
        };

        $sync = app(SchoolRoleSynchronizer::class);
        if ($child) {
            $sync->synchronize();
        }
        $result = $child ? $sync->inChild($child, $assign) : $assign();
        $access->forget();
        AuditLogger::tenant($request->user(), 'UPDATE', 'usuario_roles_adicionales', $result['user_id'],
            ['roles' => $result['previous']], ['roles' => $data['roles'], 'sede_url_token' => $data['sede_url_token'] ?? null]);

        return response()->json(['roles' => $data['roles']]);
    }

    private function requireRector(Request $request): void
    {
        abort_unless(! tenant()?->parent_id
            && ($request->user()->hasRole('rector') || $request->user()->esSuperadminPlataforma()), 403);
    }

    /**
     * Features del plan del colegio actual (para el gating).
     *
     * @return list<string>
     */
    private function planFeatures(): array
    {
        return app(TenantPlanAccess::class)->features();
    }
}
