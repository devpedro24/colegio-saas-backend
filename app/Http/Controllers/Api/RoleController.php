<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Plan;
use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /** Devuelve la matriz efectiva del colegio: roles x permisos por modulo. */
    public function index(Request $request): JsonResponse
    {
        $planFeatures = $this->planFeatures();

        // Catalogo central.
        $rbacRoles = RbacRole::orderBy('sort_order')->orderBy('id')->get();
        $permissionsPaginator = RbacPermission::orderBy('sort_order')->orderBy('id')
            ->paginate($this->resolvePerPage($request));
        $matrix = RbacMatrixCell::all()->groupBy('role_key');

        // Estado real de grants en el tenant.
        $dbRoles = Role::with('permissions:id,name')->get()->keyBy('name');

        $roles = $rbacRoles->map(fn (RbacRole $r) => ['key' => $r->key, 'label' => $r->label])->values();

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

            $modules[$perm->module][] = [
                'key' => $perm->key,
                'action' => $perm->action,
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
            ['roles' => $roles],
        ));

        return $response;
    }

    /** Activa/desactiva un permiso CONFIGURABLE (y permitido por el plan) para un rol. */
    public function togglePermission(Request $request, string $role, string $permission): JsonResponse
    {
        if (! RbacRole::where('key', $role)->exists()) {
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
        if (! $cell || $cell->type !== 'configurable') {
            abort(422, 'Este permiso no es configurable para este rol (estructural o no permitido).');
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

        return response()->json([
            'role' => $role,
            'permission' => $permission,
            'granted' => $granted,
        ]);
    }

    /**
     * Features del plan del colegio actual (para el gating).
     *
     * @return list<string>
     */
    private function planFeatures(): array
    {
        $planKey = tenant()?->plan;
        $features = $planKey ? (Plan::where('key', $planKey)->value('features') ?? []) : [];

        return is_array($features) ? $features : [];
    }
}
