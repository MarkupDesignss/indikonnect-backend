<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\AdminPermission;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $roles = AdminRole::with('permissions')->get();
        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:admin_roles,name',
            'slug' => 'required|string|unique:admin_roles,slug',
            'description' => 'nullable|string',
            'permissions' => 'array',
            'permissions.*' => 'exists:admin_permissions,id'
        ]);

        $role = AdminRole::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'description' => $request->description
        ]);

        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        return response()->json($role->load('permissions'), 201);
    }

    public function show($id)
    {
        $role = AdminRole::with('permissions')->findOrFail($id);
        return response()->json($role);
    }

    public function update(Request $request, $id)
    {
        $role = AdminRole::findOrFail($id);

        $request->validate([
            'name' => 'required|string|unique:admin_roles,name,' . $id,
            'slug' => 'required|string|unique:admin_roles,slug,' . $id,
            'description' => 'nullable|string',
            'permissions' => 'array',
            'permissions.*' => 'exists:admin_permissions,id'
        ]);

        $role->update([
            'name' => $request->name,
            'slug' => $request->slug,
            'description' => $request->description
        ]);

        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        return response()->json($role->load('permissions'));
    }

    public function destroy($id)
    {
        $role = AdminRole::findOrFail($id);

        // Check if role has admins
        if ($role->admins()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role. It has assigned admins.'
            ], 400);
        }

        $role->delete();
        return response()->json(['message' => 'Role deleted successfully']);
    }
}