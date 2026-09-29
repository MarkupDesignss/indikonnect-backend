<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CatalogueController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'file'  => 'required|file|mimes:pdf|max:20480',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');

            // Store in storage/app/public/catalogues
            $path = $file->store('catalogues', 'public');

            $catalogue = Catalogue::create([
                'title'     => $request->title,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Catalogue uploaded successfully',
                'data'    => [
                    'id'         => $catalogue->id,
                    'title'      => $catalogue->title,
                    'file_name'  => $catalogue->file_name,
                    'file_url'   => asset('storage/' . $catalogue->file_path),
                    'created_at' => $catalogue->created_at,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/catalogues/{id}
     * Get / stream PDF from catalogue
     */
    public function show(Request $request, $id)
    {
        $catalogue = Catalogue::find($id);

        if (!$catalogue) {
            return response()->json([
                'status'  => false,
                'message' => 'Catalogue not found',
            ], 404);
        }

        $fullPath = storage_path('app/public/' . $catalogue->file_path);

        if (!file_exists($fullPath)) {
            return response()->json([
                'status'  => false,
                'message' => 'File not found on server',
            ], 404);
        }

        // Option A: Return file inline (stream in browser)
        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $catalogue->file_name . '"',
        ]);

        // Option B: Force download
        // return response()->download($fullPath, $catalogue->file_name);

        // Option C: Just return URL (if using S3 / public disk)
        // return response()->json([
        //     'status' => true,
        //     'file_url' => Storage::disk('public')->url($catalogue->file_path),
        // ]);
    }

    /**
     * GET /api/admin/catalogues
     * List all catalogues
     */
    public function index()
    {
        $catalogues = Catalogue::latest()->paginate(15);

        $catalogues->getCollection()->transform(function ($catalogue) {
            $catalogue->file_url = asset('storage/' . $catalogue->file_path);

            return $catalogue;
        });

        return response()->json([
            'status' => true,
            'data'   => $catalogues,
        ]);
    }

    public function replace(Request $request, $id)
    {
        $catalogue = Catalogue::find($id);

        if (!$catalogue) {
            return response()->json([
                'status'  => false,
                'message' => 'Catalogue not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'file'  => 'required|file|mimes:pdf|max:20480', // max 20MB
            'title' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $oldPath = $catalogue->file_path;

            // Store new file
            $newPath = $file->store('catalogues', 'public');

            // Update record
            $catalogue->update([
                'title'     => $request->input('title', $catalogue->title),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $newPath,
            ]);

            // Delete old file after successful update
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Catalogue PDF replaced successfully',
                'data'    => [
                    'id'         => $catalogue->id,
                    'title'      => $catalogue->title,
                    'file_name'  => $catalogue->file_name,
                    'file_url'   => asset('storage/' . $catalogue->file_path),
                    'updated_at' => $catalogue->updated_at,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Replace failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
