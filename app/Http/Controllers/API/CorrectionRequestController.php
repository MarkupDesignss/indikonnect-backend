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
    // 🔹 USER: request bhejna (sirf reason)
    public function store(Request $request)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        // ek pending request already ho to rok do
        $exists = CorrectionRequest::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Aapki ek request already pending hai.',
            ], 422);
        }

        $cr = CorrectionRequest::create([
            'user_id' => $request->user()->id,
            'email'   => $request->user()->email,
            'reason'  => $data['reason'],
            'status'  => 'pending',
        ]);

        return response()->json([
            'message' => 'Request submit ho gayi.',
            'data'    => $cr,
        ], 201);
    }

    // 🔹 ADMIN: list
    public function index(Request $request)
    {
        $requests = CorrectionRequest::with('user:id,name,email,phone')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return response()->json($requests);
    }

    // 🔹 ADMIN: link generate + mail
    public function sendLink($id)
    {
        $cr = CorrectionRequest::with('user:id,name,email,phone')->findOrFail($id);

        if (!in_array($cr->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'Ye request closed hai.'], 422);
        }

        $token = Str::random(64);

        $cr->update([
            'status'           => 'approved',
            'token'            => $token,
            'token_expires_at' => now()->addMinutes(10),
            'token_used_at'    => null,
        ]);

        Mail::to($cr->email)->send(new PhoneUpdateLinkMail($cr, $token));

        return response()->json(['message' => 'Link email pe bhej diya.']);
    }
}
