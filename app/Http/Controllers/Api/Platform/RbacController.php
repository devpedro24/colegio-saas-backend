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
use App\Services\TenantRbacSynchronizationException;
use App\Services\TenantRbacSynchronizer;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administra el catalogo RBAC central y lo propaga a todos los tenants.
 *
 * Cada mutacion central se mantiene abierta hasta terminar la sincronizacion.
 * Si algun tenant falla, la transaccion central se revierte y se ejecuta una
 * segunda sincronizacion contra el catalogo restaurado como compensacion.
 */
class RbacController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json([
            'permissions' => RbacPermission::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RbacPermission $permission) => $this->presentPermission($permission)),
            'roles' => RbacRole::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RbacRole $role) => $this->presentRole($role)),
            'matrix' => RbacMatrixCell::all()->map(fn (RbacMatrixCell $cell) => $this->presentCell($cell)),
            'levels' => PermissionMatrix::STRUCTURAL_LEVELS,
            'features' => PlanCatalog::features(),
            'categories' => array_map(
                static fn ($key, $label) => ['key' => $key, 'label' => $label],
                array_keys(PlanCatalog::CATEGORIES),
                array_values(PlanCatalog::CATEGORIES),
            ),
            'obsolete_policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
        ]);
    }

    public function storePermission(Request $request, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/', Rule::unique('rbac_permissions', 'key')],
            'module' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'max:255'],
            'feature_key' => ['nullable', 'string', Rule::in(PlanCatalog::featureKeys())],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['is_system'] = false;
        $data['sort_order'] = (int) RbacPermission::max('sort_order') + 1;

        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'CREATE', 'rbac.permission', null, 'created',
            function () use ($data): array {
                $permission = RbacPermission::create($data);
                $presented = $this->presentPermission($permission);

                return ['id' => (string) $permission->id, 'after' => $presented, 'payload' => $presented];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(['permission' => $result['payload'], 'sync' => $result['sync']], 201);
    }

    public function updatePermission(Request $request, int $id, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $permission = RbacPermission::findOrFail($id);
        $before = $this->presentPermission($permission);
        $data = $request->validate([
            'module' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'max:255'],
            'feature_key' => ['nullable', 'string', Rule::in(PlanCatalog::featureKeys())],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'UPDATE', 'rbac.permission', $before, 'updated',
            function () use ($permission, $data): array {
                $permission->update($data);
                $presented = $this->presentPermission($permission->fresh());

                return ['id' => (string) $permission->id, 'after' => $presented, 'payload' => $presented];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(['permission' => $result['payload'], 'sync' => $result['sync']]);
    }

    public function destroyPermission(Request $request, int $id, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $permission = RbacPermission::findOrFail($id);
        if ($permission->is_system) {
            return response()->json(['message' => 'No se puede eliminar un permiso del sistema.'], 422);
        }

        $before = $this->presentPermission($permission);
        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'DELETE', 'rbac.permission', $before, 'deleted',
            function () use ($permission): array {
                RbacMatrixCell::where('permission_key', $permission->key)->delete();
                $id = (string) $permission->id;
                $permission->delete();

                return ['id' => $id, 'after' => null, 'payload' => ['deleted' => true]];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(array_merge($result['payload'], [
                'sync' => $result['sync'],
                'obsolete_policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
            ]));
    }

    public function storeRole(Request $request, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('rbac_roles', 'key')],
            'label' => ['required', 'string', 'max:120'],
        ]);
        $data['is_system'] = false;
        $data['sort_order'] = (int) RbacRole::max('sort_order') + 1;

        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'CREATE', 'rbac.role', null, 'created',
            function () use ($data): array {
                $role = RbacRole::create($data);
                $presented = $this->presentRole($role);

                return ['id' => (string) $role->id, 'after' => $presented, 'payload' => $presented];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(['role' => $result['payload'], 'sync' => $result['sync']], 201);
    }

    public function updateRole(Request $request, int $id, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $role = RbacRole::findOrFail($id);
        $before = $this->presentRole($role);
        $data = $request->validate(['label' => ['required', 'string', 'max:120']]);

        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'UPDATE', 'rbac.role', $before, 'updated',
            function () use ($role, $data): array {
                $role->update($data);
                $presented = $this->presentRole($role->fresh());

                return ['id' => (string) $role->id, 'after' => $presented, 'payload' => $presented];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(['role' => $result['payload'], 'sync' => $result['sync']]);
    }

    public function destroyRole(Request $request, int $id, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $role = RbacRole::findOrFail($id);
        if ($role->is_system) {
            return response()->json(['message' => 'No se puede eliminar un rol del sistema.'], 422);
        }

        $before = $this->presentRole($role);
        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'DELETE', 'rbac.role', $before, 'deleted',
            function () use ($role): array {
                RbacMatrixCell::where('role_key', $role->key)->delete();
                $id = (string) $role->id;
                $role->delete();

                return ['id' => $id, 'after' => null, 'payload' => ['deleted' => true]];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(array_merge($result['payload'], [
                'sync' => $result['sync'],
                'obsolete_policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
            ]));
    }

    public function setMatrixCell(Request $request, TenantRbacSynchronizer $synchronizer): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'string', Rule::exists('rbac_roles', 'key')],
            'permission_key' => ['required', 'string', Rule::exists('rbac_permissions', 'key')],
            'type' => ['required', Rule::in(['structural', 'configurable', 'denied'])],
            'level' => ['nullable', Rule::in(PermissionMatrix::STRUCTURAL_LEVELS)],
            'default_granted' => ['boolean'],
        ]);

        $existing = RbacMatrixCell::query()
            ->where('role_key', $data['role_key'])
            ->where('permission_key', $data['permission_key'])
            ->first();
        $before = $existing === null
            ? $this->deniedCell($data['role_key'], $data['permission_key'])
            : $this->presentCell($existing);

        $result = $this->mutateAndSynchronize(
            $request, $synchronizer, 'UPDATE', 'rbac.matrix', $before, 'updated',
            function () use ($data): array {
                if ($data['type'] === 'denied') {
                    RbacMatrixCell::where('role_key', $data['role_key'])
                        ->where('permission_key', $data['permission_key'])
                        ->delete();
                    $presented = $this->deniedCell($data['role_key'], $data['permission_key']);
                } else {
                    $level = $data['type'] === 'structural' ? ($data['level'] ?? 'ver') : null;
                    $cell = RbacMatrixCell::updateOrCreate(
                        ['role_key' => $data['role_key'], 'permission_key' => $data['permission_key']],
                        [
                            'type' => $data['type'],
                            'level' => $level,
                            'default_granted' => $data['type'] === 'structural'
                                ? true
                                : (bool) ($data['default_granted'] ?? false),
                        ],
                    );
                    $presented = $this->presentCell($cell);
                }

                return [
                    'id' => $data['role_key'].'|'.$data['permission_key'],
                    'after' => $presented,
                    'payload' => $presented,
                ];
            },
        );

        return $result instanceof JsonResponse
            ? $result
            : response()->json(array_merge($result['payload'], ['sync' => $result['sync']]));
    }

    /**
     * @param  Closure():array{id:string,after:?array<string,mixed>,payload:array<string,mixed>}  $mutation
     * @return array{payload:array<string,mixed>,sync:array<string,mixed>}|JsonResponse
     */
    private function mutateAndSynchronize(
        Request $request,
        TenantRbacSynchronizer $synchronizer,
        string $action,
        string $resource,
        ?array $before,
        string $event,
        Closure $mutation,
    ): array|JsonResponse {
        $mutationResult = null;

        try {
            $result = DB::connection(config('tenancy.database.central_connection'))
                ->transaction(function () use ($mutation, $synchronizer, &$mutationResult): array {
                    $mutationResult = $mutation();
                    $mutationResult['sync'] = $synchronizer->syncAll();

                    return $mutationResult;
                });
        } catch (TenantRbacSynchronizationException $exception) {
            // La transaccion central ya se revirtio. Esta segunda pasada lleva
            // los tenants sincronizados parcialmente al catalogo restaurado.
            $compensation = $synchronizer->syncAllWithResult();
            $details = [
                'requested' => $mutationResult['after'] ?? null,
                'sync' => $exception->result,
                'compensation' => $compensation,
            ];

            AuditLogger::platform(
                $request->user(),
                'RBAC_SYNC_FAILED',
                $resource,
                $mutationResult['id'] ?? null,
                $before,
                $details,
                'Mutacion central revertida; compensacion de tenants ejecutada.',
            );

            return response()->json([
                'message' => 'La mutacion RBAC fue revertida porque no pudo propagarse a todos los tenants.',
                'sync' => $exception->result,
                'compensation' => $compensation,
            ], 503);
        }

        AuditLogger::platform(
            $request->user(),
            $action,
            $resource,
            $result['id'],
            $before,
            $result['after'],
            'Sincronizacion RBAC completada en '.$result['sync']['attempted'].' tenant(s). Politica de obsoletos: '
                .TenantRbacSynchronizer::OBSOLETE_POLICY.'.',
        );
        PlatformDataChanged::dispatch('rbac', $event);

        return ['payload' => $result['payload'], 'sync' => $result['sync']];
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function presentCell(RbacMatrixCell $cell): array
    {
        return [
            'role_key' => $cell->role_key,
            'permission_key' => $cell->permission_key,
            'type' => $cell->type,
            'level' => $cell->level,
            'default_granted' => $cell->default_granted,
        ];
    }

    /** @return array<string, mixed> */
    private function deniedCell(string $roleKey, string $permissionKey): array
    {
        return [
            'role_key' => $roleKey,
            'permission_key' => $permissionKey,
            'type' => 'denied',
            'level' => null,
            'default_granted' => false,
        ];
    }
}
