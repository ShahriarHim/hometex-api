<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * IMS-configurable roles and permissions management.
 * All endpoints require 'admin' middleware (set in routes).
 */
class RoleController extends Controller
{
    private const PROTECTED_ROLES = ['admin', 'customer', 'corporate'];

    public function index(): JsonResponse
    {
        $roles = Role::withCount('permissions')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id'               => $role->id,
                'name'             => $role->name,
                'permissions_count' => $role->permissions_count,
                'permissions'      => $role->permissions->pluck('name'),
                'is_protected'     => in_array($role->name, self::PROTECTED_ROLES),
            ]);

        return response()->json(['status' => 'success', 'data' => $roles]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id'           => $role->id,
                'name'         => $role->name,
                'permissions'  => $role->permissions->pluck('name'),
                'is_protected' => in_array($role->name, self::PROTECTED_ROLES),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:roles,name',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create(['name' => $request->name, 'guard_name' => 'sanctum']);

        if ($request->filled('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'Role created', 'data' => ['id' => $role->id]], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES)) {
            return response()->json(['status' => 'error', 'message' => "Role '{$role->name}' cannot be renamed."], 422);
        }

        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $role->id,
        ]);

        $role->update(['name' => $request->name]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'Role updated']);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES)) {
            return response()->json(['status' => 'error', 'message' => "Role '{$role->name}' is protected and cannot be deleted."], 422);
        }

        $role->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'Role deleted']);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        // Prevent removing all permissions from admin (safety guard)
        if ($role->name === 'admin' && empty($request->permissions)) {
            return response()->json(['status' => 'error', 'message' => 'Admin role must retain at least one permission.'], 422);
        }

        $role->syncPermissions($request->permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        ActivityLogService::rolePermissionsUpdated($role->name);

        return response()->json(['status' => 'success', 'message' => 'Permissions updated']);
    }

    public function permissions(): JsonResponse
    {
        $grouped = Permission::all()
            ->groupBy(fn (Permission $p) => explode('.', $p->name)[0])
            ->map(fn ($perms, $module) => [
                'module'      => $module,
                'permissions' => $perms->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $grouped]);
    }

    public function userRoles(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->id,
                'roles'   => $user->getRoleNames(),
            ],
        ]);
    }

    public function assignUserRoles(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'roles'   => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user->syncRoles($request->roles);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'User roles updated']);
    }
}
