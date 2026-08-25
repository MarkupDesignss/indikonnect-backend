<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function index()
    {
        $admins = Admin::with('roles')->get();
        return response()->json($admins);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
            'roles' => 'array',
            'roles.*' => 'exists:admin_roles,id'
        ]);

        $admin = Admin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        if ($request->has('roles')) {
            $admin->roles()->sync($request->roles);
        }

        return response()->json($admin->load('roles'), 201);
    }

    public function update(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string',
            'email' => 'nullable|email',
            'password' => 'nullable|string|min:8',
            'roles' => 'array',
            'roles.*' => 'exists:admin_roles,id'
        ]);
        $data = [
            'name' => $request->name,
            'email' => $request->email ?? $admin->email
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $admin->update($data);

        if ($request->has('roles')) {
            $admin->roles()->sync($request->roles);
        }

        return response()->json($admin->load('roles'));
    }

    public function destroy($id)
    {
        // Prevent deleting yourself
        if (auth()->id() == $id) {
            return response()->json([
                'message' => 'Cannot delete your own account.'
            ], 400);
        }

        $admin = Admin::findOrFail($id);
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully']);
    }
}