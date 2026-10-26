<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Sdks\Partner\IgniteClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IgniteExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * List export files Ignite holds for this partner.
     *
     * GET /api/admin/ignite/exports
     *
     * Response:
     * {
     *   "success": true,
     *   "data": {
     *     "files": [
     *       { "name": "2026_W43_wd.csv",     "size": 412, "modified_at": "2026-10-26 10:14:02" },
     *       { "name": "2026_W43_ledger.csv", "size": 2840, "modified_at": "2026-10-26 10:14:01" }
     *     ]
     *   }
     * }
     */
    public function list()
    {
        try {
            $response = $this->ignite->exportList();
        } catch (\Throwable $e) {
            Log::error('Ignite exportList threw', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ignite unreachable. Please try again.',
            ], 503);
        }

        if (($response['code'] ?? null) !== 0) {
            Log::warning('Ignite exportList refused', [
                'code' => $response['code'] ?? null,
                'msg'  => $response['msg'] ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => $response['msg'] ?? 'Ignite refused the request.',
                'code'    => $response['code'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'files' => $response['data']['files'] ?? [],
            ],
        ]);
    }

    /**
     * Download one export file as CSV.
     *
     * GET /api/admin/ignite/exports/download?name=2026_W43_wd.csv
     *
     * Returns the raw CSV bytes on success, JSON error on failure.
     */
    public function download(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|regex:/^[0-9A-Za-z_\-\.]+$/',
        ]);

        $name = $validated['name'];

        try {
            $csv = $this->ignite->exportDownload($name);
        } catch (RuntimeException $e) {
            // SDK throws RuntimeException with code = ResultCode on refusal
            Log::warning('Ignite exportDownload refused', [
                'name'  => $name,
                'code'  => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Ignite exportDownload threw', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ignite unreachable. Please try again.',
            ], 503);
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}