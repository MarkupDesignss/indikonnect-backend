<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\PhoneUpdateLinkMail;
use App\Mail\EmailUpdateLinkMail;
use App\Models\CorrectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CorrectionRequestController extends Controller
{
    private function getCorrectionRejectionDays(): int
    {
        return (int) DB::table('settings')
            ->where('group', 'Correction')
            ->where('key', 'correction_rejection_duation')
            ->value('value');
    }

    // USER: Submit correction request (phone / name_dob / email)
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'type'      => 'required|in:phone_request,name_dob_request,email_request',
                'reason'    => 'required|string|max:1000',
                'new_email' => 'nullable|email|max:255|unique:users,email',
            ]);

            $user   = $request->user();
            $userId = $user->id;

            // Prevent duplicate pending request of same type
            $exists = CorrectionRequest::where('user_id', $userId)
                ->where('status', 'pending')
                ->where('type', $data['type'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You already have a pending correction request of this type.',
                ], 422);
            }

            // Rejection cooldown
            $rejectionDays = $this->getCorrectionRejectionDays();

            $recentlyRejected = CorrectionRequest::where('user_id', $userId)
                ->where('type', $data['type'])
                ->where('status', 'rejected')
                ->whereNotNull('rejected_at')
                ->where('rejected_at', '>', now()->subDays($rejectionDays))
                ->latest('rejected_at')
                ->first();

            if ($recentlyRejected) {
                $nextAllowedAt = Carbon::parse($recentlyRejected->rejected_at)
                    ->addDays($rejectionDays);

                return response()->json([
                    'status'         => false,
                    'message'        => 'Your previous request was rejected. You can submit a new request after '
                        . $nextAllowedAt->format('d M Y, h:i A') . '.',
                    'retry_after'    => $nextAllowedAt->toDateTimeString(),
                    'rejection_days' => $rejectionDays,
                ], 422);
            }

            // Create request
            $cr = CorrectionRequest::create([
                'user_id'   => $userId,
                'email'     => $user->email ?? null,
                'new_email' => null,
                'reason'    => $data['reason'],
                'type'      => $data['type'],
                'status'    => 'pending',
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Your correction request has been submitted successfully.',
                'data'    => $cr,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Correction request failed.', [
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

    // ADMIN: List
    public function index(Request $request)
    {
        $requests = CorrectionRequest::with('user:id,full_name,email,phone')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return response()->json($requests);
    }

    // ADMIN: Send link
    public function sendLink($id)
    {
        $cr = CorrectionRequest::with('user:id,full_name,email,phone')
            ->findOrFail($id);

        if (!in_array($cr->status, ['pending', 'approved'])) {
            return response()->json([
                'message' => 'This correction request is already closed.',
            ], 422);
        }

        $token = \Illuminate\Support\Str::random(64);

        $cr->update([
            'status'           => 'approved',
            'token'            => $token,
            'token_expires_at' => now()->addMinutes(10),
            'token_used_at'    => null,
        ]);

        $cr->refresh();

        if ($cr->type === 'phone_request') {
            \Illuminate\Support\Facades\Mail::to($cr->email)->send(new PhoneUpdateLinkMail($cr, $token));
        } elseif ($cr->type === 'email_request') {
            \Illuminate\Support\Facades\Mail::to($cr->email)->send(new EmailUpdateLinkMail($cr, $token));
        } else {
            \Illuminate\Support\Facades\Mail::to($cr->email)
                ->send(new \App\Mail\CorrectionLinkMail($cr));
        }

        return response()->json([
            'message' => 'The correction link has been sent to your email address.',
        ]);
    }
}
