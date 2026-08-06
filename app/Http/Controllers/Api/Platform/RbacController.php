<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\PlatformDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use App\Plans\PlanCatalog;
use App\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administracion del catalogo RBAC (roles, permisos y matriz global) desde el
 * panel del superadministrador. Rutas centrales, protegidas por auth + platform.
 *
 * Esta es la FUENTE DE VERDAD del RBAC. Los colegios (tenants) siembran/sincronizan
 * sus roles/permisos de spatie a partir de aqui, filtrados por su plan.
 */
class RbacController extends Controller
{
    /** Catalogo completo: roles, permisos, matriz + features de plan (para el gating). */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'permissions' => RbacPermission::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RbacPermission $p) => $this->presentPermission($p)),
            'roles' => RbacRole::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RbacRole $r) => $this->presentRole($r)),
            'matrix' => RbacMatrixCell::all()->map(fn (RbacMatrixCell $c) => [
                'role_key' => $c->role_key,
                'permission_key' => $c->permission_key,
                'type' => $c->type,
                'level' => $c->level,
                'default_granted' => $c->default_granted,
            ]),
            'levels' => PermissionMatrix::STRUCTURAL_LEVELS,
            'features' => PlanCatalog::features(),
            'categories' => array_map(
                static fn ($k, $l) => ['key' => $k, 'label' => $l],
                array_keys(PlanCatalog::CATEGORIES),
                array_values(PlanCatalog::CATEGORIES),
            ),
        ]);
    }

    // ---- Permisos ----

    public function storePermission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/', 'unique:pgsql.rbac_permissions,key'],
            'module' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'max:255'],
            'feature_key' => ['nullable', 'string', Rule::in(PlanCatalog::featureKeys())],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['is_system'] = false;
        $data['sort_order'] = (int) RbacPermission::max('sort_order') + 1;

        $permission = RbacPermission::create($data);

        PlatformDataChanged::dispatch('rbac', 'created');

        return response()->json(['permission' => $this->presentPermission($permission)], 201);
    }

    public function updatePermission(Request $request, int $id): JsonResponse
    {
        $permission = RbacPermission::findOrFail($id);

        // La `key` es inmutable (la referencian la matriz y spatie en los tenants).
        $data = $request->validate([
            'module' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'max:255'],
            'feature_key' => ['nullable', 'string', Rule::in(PlanCatalog::featureKeys())],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $permission->update($data);

        PlatformDataChanged::dispatch('rbac', 'updated');

        return response()->json(['permission' => $this->presentPermission($permission)]);
    }

    public function destroyPermission(int $id): JsonResponse
    {
        $permission = RbacPermission::findOrFail($id);

        if ($permission->is_system) {
            return response()->json(['message' => 'No se puede eliminar un permiso del sistema.'], 422);
        }

        RbacMatrixCell::where('permission_key', $permission->key)->delete();
        $permission->delete();

        PlatformDataChanged::dispatch('rbac', 'deleted');

        return response()->json(['deleted' => true]);
    }

    // ---- Roles ----

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'unique:pgsql.rbac_roles,key'],
            'label' => ['required', 'string', 'max:120'],
        ]);

        $data['is_system'] = false;
        $data['sort_order'] = (int) RbacRole::max('sort_order') + 1;

        $role = RbacRole::create($data);

        PlatformDataChanged::dispatch('rbac', 'created');

        return response()->json(['role' => $this->presentRole($role)], 201);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $role = RbacRole::findOrFail($id);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
        ]);

        $role->update($data);

        PlatformDataChanged::dispatch('rbac', 'updated');

        return response()->json(['role' => $this->presentRole($role)]);
    }

    public function destroyRole(int $id): JsonResponse
    {
        $role = RbacRole::findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'No se puede eliminar un rol del sistema.'], 422);
        }

        RbacMatrixCell::where('role_key', $role->key)->delete();
        $role->delete();

        PlatformDataChanged::dispatch('rbac', 'deleted');

        return response()->json(['deleted' => true]);
    }

    // ---- Matriz ----

    /** Fija (o borra) la celda (rol, permiso). type=denied borra la fila. */
    public function setMatrixCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'string', 'exists:pgsql.rbac_roles,key'],
            'permission_key' => ['required', 'string', 'exists:pgsql.rbac_permissions,key'],
            'type' => ['required', Rule::in(['structural', 'configurable', 'denied'])],
            'level' => ['nullable', Rule::in(PermissionMatrix::STRUCTURAL_LEVELS)],
            'default_granted' => ['boolean'],
        ]);

        if ($data['type'] === 'denied') {
            RbacMatrixCell::where('role_key', $data['role_key'])
                ->where('permission_key', $data['permission_key'])
                ->delete();

            return response()->json(['role_key' => $data['role_key'], 'permission_key' => $data['permission_key'], 'type' => 'denied']);
        }

        // Estructural exige nivel; configurable lo ignora.
        $level = $data['type'] === 'structural' ? ($data['level'] ?? 'ver') : null;

        $cell = RbacMatrixCell::updateOrCreate(
            ['role_key' => $data['role_key'], 'permission_key' => $data['permission_key']],
            [
                'type' => $data['type'],
                'level' => $level,
                'default_granted' => $data['type'] === 'structural' ? true : (bool) ($data['default_granted'] ?? false),
            ],
        );

        PlatformDataChanged::dispatch('rbac', 'updated');

        return response()->json([
            'role_key' => $cell->role_key,
            'permission_key' => $cell->permission_key,
            'type' => $cell->type,
            'level' => $cell->level,
            'default_granted' => $cell->default_granted,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPermission(RbacPermission $permission): array
    {
        return [
            'id' => $permission->id,
            'key' => $permission->key,
            'module' => $permission->module,
            'action' => $permission->action,
            'feature_key' => $permission->feature_key,
            'description' => $permission->description,
            'is_system' => $permission->is_system,
            'sort_order' => $permission->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRole(RbacRole $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'label' => $role->label,
            'is_system' => $role->is_system,
            'sort_order' => $role->sort_order,
        ];
    }
}
