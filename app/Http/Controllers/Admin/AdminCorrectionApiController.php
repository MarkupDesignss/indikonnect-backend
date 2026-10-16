<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CorrectionLinkMail;
use App\Models\CorrectionRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminCorrectionApiController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'data'   => CorrectionRequest::with('user')->latest()->get(),
        ]);
    }

    // POST /api/admin/correction/requests/{id}/send-link
    public function sendLink($id)
    {
        $req = CorrectionRequest::findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json([
                'status'  => false,
                'message' => 'Request already processed.',
            ], 422);
        }

        $req->update([
            'status'           => 'approved',
            'token'            => Str::random(64),
            'token_expires_at' => now()->addHours(24),
        ]);

        Mail::to($req->user->email)->send(new CorrectionLinkMail($req));

        return response()->json([
            'status'  => true,
            'message' => 'Link sent to user email.',
            'data'    => $req,
        ]);
    }
}
