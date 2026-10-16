<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\AdminCorrectionMail;
use App\Mail\UserAckMail;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;


class CorrectionApiController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email'         => 'required|email',
            'reason'        => ['required', Rule::in(array_keys(config('correction.reasons')))],
            'full_name'     => 'required|string|max:150',
            'date_of_birth' => 'required|date|before:today',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'No account found with this email.',
            ], 404);
        }

        // Prevent duplicate pending requests
        $pending = CorrectionRequest::where('email', $data['email'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($pending) {
            return response()->json([
                'status'  => false,
                'message' => 'You already have a request in progress.',
            ], 422);
        }

        $req = CorrectionRequest::create([
            'user_id'       => $user->id,
            'email'         => $user->email,
            'reason'        => $data['reason'],
            'full_name'     => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'],
            'status'        => 'pending',
        ]);

        // Mail to admin
        Mail::to(env('ADMIN_EMAIL'))->send(new AdminCorrectionMail($req));

        // Ack mail to user (unke diye email pe)
        Mail::to($user->email)->send(new UserAckMail($req));

        return response()->json([
            'status'  => true,
            'message' => 'Request sent. Check your email for confirmation.',
            'data'    => $req,
        ]);
    }

    // GET /api/correction/reasons  → frontend dropdown ke liye
    public function reasons()
    {
        return response()->json([
            'status' => true,
            'data'   => collect(config('correction.reasons'))
                ->map(fn($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function index()
    {
        return response()->json([
            'status' => true,
            'data'   => CorrectionRequest::with('user')->latest()->get(),
        ]);
    }
}
