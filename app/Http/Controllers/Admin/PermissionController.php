<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AdminPermission;

class PermissionController extends Controller
{
    public function index()
    {
        return response()->json(AdminPermission::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:admin_permissions,name',
            'slug' => 'required|string|unique:admin_permissions,slug',
            'module' => 'required|string',
            'action' => 'required|string'
        ]);

        $permission = AdminPermission::create($request->all());
        return response()->json($permission, 201);
    }

    public function update(Request $request, $id)
    {
        $permission = AdminPermission::findOrFail($id);

        $request->validate([
            'name' => 'required|string|unique:admin_permissions,name,' . $id,
            'slug' => 'required|string|unique:admin_permissions,slug,' . $id,
            'module' => 'required|string',
            'action' => 'required|string'
        ]);

        $permission->update($request->all());
        return response()->json($permission);
    }

    public function destroy($id)
    {
        $permission = AdminPermission::findOrFail($id);

        // Check if permission is assigned to any role
        if ($permission->roles()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete permission. It is assigned to roles.'
            ], 400);
        }

        $permission->delete();
        return response()->json(['message' => 'Permission deleted successfully']);
    }

    // Get all modules with their permissions
    public function getModules()
    {
        $permissions = AdminPermission::all();
        $modules = [];

        foreach ($permissions as $permission) {
            if (!isset($modules[$permission->module])) {
                $modules[$permission->module] = [];
            }
            $modules[$permission->module][] = $permission;
        }

        return response()->json($modules);
    }
}
