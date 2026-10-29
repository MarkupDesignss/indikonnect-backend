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

class CorrectionRequestController extends Controller
{
    // USER: Submit a correction request
    public function store(Request $request)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        // Prevent duplicate pending requests
        $exists = CorrectionRequest::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'You already have a pending correction request.',
            ], 422);
        }

        $cr = CorrectionRequest::create([
            'user_id' => $request->user()->id,
            'email'   => $request->user()->email,
            'reason'  => $data['reason'],
            'type'  => 'phone_request',
            'status'  => 'pending',
        ]);

        return response()->json([
            'message' => 'Your correction request has been submitted successfully.',
            'data'    => $cr,
        ], 201);
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
            'token_expires_at' => now()->addMinutes(10),
            'token_used_at'    => null,
        ]);

        Mail::to($cr->email)->send(new PhoneUpdateLinkMail($cr, $token));

        return response()->json([
            'message' => 'The correction link has been sent to your email address.',
        ]);
    }
}
