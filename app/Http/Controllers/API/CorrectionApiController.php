<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\AdminCorrectionMail;
use App\Mail\UserAckMail;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;

class CorrectionApiController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email'  => 'required|email',
            'reason' => ['required', Rule::in(array_keys(config('correction.reasons')))],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'No account found with this email.',
            ], 404);
        }

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
            'full_name'     => null,
            'date_of_birth' => null,
            'status'        => 'pending',
        ]);

        Mail::to(env('ADMIN_EMAIL'))->send(new AdminCorrectionMail($req));
        Mail::to($user->email)->send(new UserAckMail($req));

        return response()->json([
            'status'  => true,
            'message' => 'Request sent. Check your email for confirmation.',
            'data'    => $req,
            'fields'  => $req->editable_fields,
        ]);
    }

    public function reasons()
    {
        return response()->json([
            'status' => true,
            'data'   => collect(config('correction.reasons'))
                ->map(fn($label, $key) => [
                    'key'    => $key,
                    'label'  => $label,
                    'fields' => config("correction.reason_fields.$key", []),
                ])
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
