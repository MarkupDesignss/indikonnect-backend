<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Common validation rules.
     */
    protected function rules(?int $ignoreId = null, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name'           => [$required, 'string', 'max:255'],
            'code'           => [
                $required,
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')->ignore($ignoreId),
            ],
            'address_line_1' => [$required, 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city'           => [$required, 'string', 'max:100'],
            'state'          => [$required, 'string', 'max:100'],
            'pincode'        => [$required, 'string', 'max:10'],
            'country'        => ['nullable', 'string', 'max:100'],
            'contact_person' => [$required, 'string', 'max:255'],
            'contact_number' => [$required, 'string', 'max:20'],
            'contact_email'  => ['nullable', 'email', 'max:255'],
            'is_active'      => ['nullable', 'boolean'],
            'is_default'     => ['nullable', 'boolean'],
            'total_capacity' => ['nullable', 'integer', 'min:0'],
            'opening_time'   => ['nullable', 'date_format:H:i'],
            'closing_time'   => ['nullable', 'date_format:H:i', 'after:opening_time'],
        ];
    }

    /**
     * GET /api/warehouses
     */
    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('contact_number', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->input('status') === 'active');
            })
            ->when($request->filled('city'), function ($query) use ($request) {
                $query->where('city', $request->input('city'));
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $warehouses,
        ]);
    }

    /**
     * POST /api/warehouses
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $warehouse = DB::transaction(function () use ($data) {
            if (!empty($data['is_default'])) {
                Warehouse::where('is_default', true)->update(['is_default' => false]);
            }

            return Warehouse::create($data);
        });

        return response()->json([
            'success' => true,
            'message' => 'Warehouse created successfully.',
            'data'    => $warehouse,
        ], 201);
    }

    /**
     * GET /api/warehouses/{warehouse}
     */
    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $warehouse,
        ]);
    }

    /**
     * PUT/PATCH /api/warehouses/{warehouse}
     */
    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validate($this->rules($warehouse->id, true));

        DB::transaction(function () use ($data, $warehouse) {
            if (!empty($data['is_default']) && !$warehouse->is_default) {
                Warehouse::where('id', '!=', $warehouse->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $warehouse->update($data);
        });

        return response()->json([
            'success' => true,
            'message' => 'Warehouse updated successfully.',
            'data'    => $warehouse->fresh(),
        ]);
    }
}
