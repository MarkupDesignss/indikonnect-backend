<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // public function index(Request $request)
    // {
    //     $query = AuditLog::with('user');

    //     // Simple filters
    //     if ($request->filled('days')) {
    //         $query->where('created_at', '>=', now()->subDays($request->days));
    //     }

    //     if ($request->filled('action')) {
    //         $query->where('action', $request->action);
    //     }

    //     if ($request->filled('module')) {
    //         $query->where('module', $request->module);
    //     }

    //     $logs = $query->orderBy('created_at', 'desc')
    //                   ->paginate($request->per_page ?? 20);

    //     return response()->json([
    //         'success' => true,
    //         'data' => $logs->items(),
    //         'pagination' => [
    //             'current_page' => $logs->currentPage(),
    //             'per_page' => $logs->perPage(),
    //             'total' => $logs->total(),
    //             'last_page' => $logs->lastPage(),
    //         ],
    //     ]);
    // }

    public function index(Request $request)
    {
        try {
            $query = AuditLog::query();

            // Optional filters
            if ($request->filled('action')) {
                $query->where('action', $request->action);
            }

            if ($request->filled('module')) {
                $query->where('module', $request->module);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $logs = $query
                ->latest('created_at')
                ->get()
                ->map(function ($log) {

                    $oldValues = $log->old_values;
                    $newValues = $log->new_values;

                    // Decode JSON if stored as string
                    if (is_string($oldValues)) {
                        $oldValues = json_decode($oldValues, true);
                    }

                    if (is_string($newValues)) {
                        $newValues = json_decode($newValues, true);
                    }

                    return [
                        'id' => $log->id,
                        'user_id' => $log->user_id,
                        'action' => $log->action,
                        'module' => $log->module,

                        'old_values' => $oldValues ?: null,
                        'new_values' => $newValues ?: null,

                        'ip_address' => $log->ip_address,

                        'created_at' => $log->created_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Audit logs fetched successfully',
                'data' => $logs,
                'total' => $logs->count(),
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        $query = AuditLog::with('user');

        if ($request->filled('days')) {
            $query->where('created_at', '>=', now()->subDays($request->days));
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $filename = 'audit_log_' . date('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'User', 'Action', 'Module', 'Old Values', 'New Values', 'IP', 'Timestamp']);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->user ? $log->user->name : 'System',
                    $log->action,
                    $log->module,
                    json_encode($log->old_values),
                    json_encode($log->new_values),
                    $log->ip_address,
                    $log->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
