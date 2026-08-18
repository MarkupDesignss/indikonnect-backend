<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GrowthStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;

class GrowthStepController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mainTitle = GrowthStep::whereNotNull('title')->value('title');

        $steps = GrowthStep::ordered()
            ->select([
                'number',
                'subtitle',
                'description',
                'order',
                'is_active',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $mainTitle,
                'steps' => $steps,
                'total_steps' => $steps->count(),
            ]
        ]);
    }
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'number' => 'required|string|max:10',
            'subtitle' => 'required|string|max:255',
            'description' => 'required|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // If title is provided, remove title from all other records
        if (!empty($data['title'])) {
            GrowthStep::whereNotNull('title')->update(['title' => null]);
        }

        $step = GrowthStep::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Growth step created successfully',
            'data' => $step,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $step = GrowthStep::find($id);

        if (!$step) {
            return response()->json([
                'success' => false,
                'message' => 'Growth step not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'number' => 'sometimes|required|string|max:10',
            'subtitle' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // If title is provided, remove title from all other records
        if (!empty($data['title'])) {
            GrowthStep::where('id', '!=', $id)->whereNotNull('title')->update(['title' => null]);
        }

        $step->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Growth step updated successfully',
            'data' => $step->fresh(),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $step = GrowthStep::find($id);

        if (!$step) {
            return response()->json([
                'success' => false,
                'message' => 'Growth step not found'
            ], 404);
        }

        $step->delete();

        return response()->json([
            'success' => true,
            'message' => 'Growth step deleted successfully',
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'steps' => 'required|array',
            'steps.*.id' => 'required|exists:growth_steps,id',
            'steps.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->steps as $stepData) {
            GrowthStep::where('id', $stepData['id'])->update(['order' => $stepData['order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Steps reordered successfully',
        ]);
    }
}
