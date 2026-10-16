<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\AdminCorrectionMail;
use App\Mail\UserAckMail;
use App\Models\CorrectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;


class CorrectionApiController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'full_name'     => 'required|string|max:150',
            'date_of_birth' => 'required|date|before:today',
        ]);

        $req = CorrectionRequest::create([
            'user_id'       => $user->id,
            'full_name'     => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'],
            'status'        => 'pending',
        ]);

        Mail::to(env('ADMIN_EMAIL'))->send(new AdminCorrectionMail($req));
        Mail::to($user->email)->send(new UserAckMail($req));

        return response()->json([
            'status'  => true,
            'message' => 'Request sent to admin.',
            'data'    => $req,
        ]);
    }

    public function index()
    {
        $requests = CorrectionRequest::with('user')->latest()->get();

        return response()->json([
            'status' => true,
            'data'   => $requests,
        ]);
    }
}
