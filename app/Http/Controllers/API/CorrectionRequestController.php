<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\PhoneUpdateLinkMail;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CorrectionRequestController extends Controller
{
    private function getCorrectionRejectionDays(): int
    {
        return (int) DB::table('settings')
            ->where('group', 'Correction')
            ->where('key', 'correction_rejection_duation')
            ->value('value');
    }
    // USER: Submit a correction request
    public function store(Request $request)
    {
        try {

            $data = $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $userId = $request->user()->id;

            // Prevent duplicate pending requests
            $exists = CorrectionRequest::where('user_id', $userId)
                ->where('status', 'pending')
                ->exists();

            if ($exists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You already have a pending correction request.',
                ], 422);
            }

            // Get rejection duration from settings
            $rejectionDays = $this->getCorrectionRejectionDays();

            // Check recently rejected request
            $recentlyRejected = CorrectionRequest::where('user_id', $userId)
                ->where('status', 'rejected')
                ->whereNotNull('rejected_at')
                ->where('rejected_at', '>', now()->subDays($rejectionDays))
                ->latest('rejected_at')
                ->first();

            if ($recentlyRejected) {

                $nextAllowedAt = Carbon::parse($recentlyRejected->rejected_at)
                    ->addDays($rejectionDays);

                return response()->json([
                    'status'  => false,
                    'message' => 'Your previous request was rejected. You can submit a new request after '
                        . $nextAllowedAt->format('d M Y, h:i A') . '.',
                    'retry_after' => $nextAllowedAt->toDateTimeString(),
                    'rejection_days' => $rejectionDays,
                ], 422);
            }

            // Create correction request
            $cr = CorrectionRequest::create([
                'user_id' => $userId,
                'email'   => $request->user()->email,
                'reason'  => $data['reason'],
                'type'    => 'phone_request',
                'status'  => 'pending',
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Your correction request has been submitted successfully.',
                'data'    => $cr,
            ], 201);
        } catch (\Throwable $e) {

            Log::error('Phone correction request failed.', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }

    // ADMIN: List correction requests
    public function index(Request $request)
    {
        $requests = CorrectionRequest::with('user:id,full_name,email,phone')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return response()->json($requests);
    }

    // ADMIN: Generate correction link and send email
    public function sendLink($id)
    {
        $cr = CorrectionRequest::with('user:id,full_name,email,phone')
            ->findOrFail($id);

        if (!in_array($cr->status, ['pending', 'approved'])) {
            return response()->json([
                'message' => 'This correction request is already closed.',
            ], 422);
        }

        $token = Str::random(64);

        $cr->update([
            'status'           => 'approved',
            'token'            => $token,
            'token_expires_at' => now()->addMinutes(1000),
            'token_used_at'    => null,
        ]);

        Mail::to($cr->email)->send(new PhoneUpdateLinkMail($cr, $token));

        return response()->json([
            'message' => 'The correction link has been sent to your email address.',
        ]);
    }
}
