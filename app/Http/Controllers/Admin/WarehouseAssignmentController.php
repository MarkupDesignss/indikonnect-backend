<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AdminWarehouseAssignment;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseAssignmentController extends Controller
{
    /**
     * GET /api/warehouse-assignments
     * List all assignments (with filters)
     */
    public function index(Request $request): JsonResponse
    {
        $assignments = AdminWarehouseAssignment::with([
            'admin:id,name,email',
            'warehouse:id,name,code,city',
            'role:id,name,slug',
        ])
            ->when($request->filled('warehouse_id'), fn($q) =>
            $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('admin_id'), fn($q) =>
            $q->where('admin_id', $request->admin_id))
            ->when($request->filled('role_id'), fn($q) =>
            $q->where('role_id', $request->role_id))
            ->when($request->filled('is_active'), fn($q) =>
            $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $assignments,
        ]);
    }

    /**
     * POST /api/warehouse-assignments
     * Assign an admin + role to a warehouse
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admin_id'         => ['required', 'exists:admins,id'],
            'warehouse_id'     => ['required', 'exists:warehouses,id'],
            'role_id'          => ['required', 'exists:admin_admin_role,id'],
            'is_primary'       => ['nullable', 'boolean'],
            'assigned_from'    => ['nullable', 'date'],
            'assigned_until'   => ['nullable'],
            'is_active'        => ['nullable', 'boolean'],
            'notes'            => ['nullable', 'string'],
        ]);

        // Duplicate check (admin + warehouse)
        $exists = AdminWarehouseAssignment::where('admin_id', $data['admin_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This admin is already assigned to this warehouse.',
            ], 422);
        }

        $assignment = DB::transaction(function () use ($data, $request) {
            if (!empty($data['is_primary'])) {
                AdminWarehouseAssignment::where('admin_id', $data['admin_id'])
                    ->update(['is_primary' => false]);
            }

            return AdminWarehouseAssignment::create([
                'admin_id'         => $data['admin_id'],
                'warehouse_id'     => $data['warehouse_id'],
                'role_id'          => $data['role_id'],                   // NEW
                'is_primary'       => $data['is_primary'] ?? false,
                'assigned_from'    => $data['assigned_from'] ?? now()->toDateString(),
                'assigned_until'   => $data['assigned_until'] ?? null,
                'is_active'        => $data['is_active'] ?? true,
                'assigned_by'      => $request->user()->id,
                'notes'            => $data['notes'] ?? null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Admin assigned to warehouse with role successfully.',
            'data'    => $assignment->load(['admin', 'warehouse', 'role']),
        ], 201);
    }

    /**
     * GET /api/warehouse-assignments/{id}
     */
    public function show($id): JsonResponse
    {
        $assignment = AdminWarehouseAssignment::with(['admin', 'warehouse', 'role'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $assignment,
        ]);
    }

    /**
     * PUT /api/warehouse-assignments/{id}
     * Update role, dates, primary, active status
     */
    public function update(Request $request, $id): JsonResponse
    {
        $assignment = AdminWarehouseAssignment::findOrFail($id);

        $data = $request->validate([
            'role_id'          => ['nullable', 'exists:admin_roles,id'],   // NEW
            'is_primary'       => ['nullable', 'boolean'],
            'assigned_from'    => ['nullable', 'date'],
            'assigned_until'   => ['nullable', 'date', 'after:assigned_from'],
            'is_active'        => ['nullable', 'boolean'],
            'notes'            => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($data, $assignment) {
            if (!empty($data['is_primary']) && !$assignment->is_primary) {
                AdminWarehouseAssignment::where('admin_id', $assignment->admin_id)
                    ->where('id', '!=', $assignment->id)
                    ->update(['is_primary' => false]);
            }

            $assignment->update($data);
        });

        return response()->json([
            'success' => true,
            'message' => 'Assignment updated successfully.',
            'data'    => $assignment->fresh(['admin', 'warehouse', 'role']),
        ]);
    }

    /**
     * DELETE /api/warehouse-assignments/{id}
     */
    public function destroy($id): JsonResponse
    {
        $assignment = AdminWarehouseAssignment::findOrFail($id);

        $assignment->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Assignment removed successfully.',
        ]);
    }

    /**
     * GET /api/warehouses/{warehouse}/admins
     * Get all admins assigned to a specific warehouse (with their roles)
     */
    public function warehouseAdmins($warehouseId): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        $assignments = AdminWarehouseAssignment::with(['admin:id,name,email', 'role:id,name,slug'])
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success'   => true,
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'data'      => $assignments,
        ]);
    }

    /**
     * GET /api/admins/{admin}/warehouses
     * Get all warehouses assigned to a specific admin (with roles)
     */
    public function adminWarehouses($adminId): JsonResponse
    {
        $admin = Admin::findOrFail($adminId);

        $assignments = AdminWarehouseAssignment::with(['warehouse', 'role:id,name,slug'])
            ->where('admin_id', $adminId)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->get();

        return response()->json([
            'success' => true,
            'admin'   => $admin->only(['id', 'name', 'email']),
            'data'    => $assignments,
        ]);
    }
}
