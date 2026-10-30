<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CorrectionLinkMail;
use App\Mail\PhoneUpdateLinkMail;
use App\Models\CorrectionRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminCorrectionApiController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'data'   => CorrectionRequest::with('user')->latest()->get(),
        ]);
    }

    public function sendLink($id)
    {
        $req = CorrectionRequest::findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json([
                'status'  => false,
                'message' => 'Request already processed.',
            ], 422);
        }

        $token = Str::random(64);

        $req->update([
            'status'           => 'approved',
            'token'            => $token,
            'token_expires_at' => now()->addMinutes(10),
            'token_used_at'    => null,
        ]);

        // Choose the correct mail based on request type
        switch ($req->type) {
            case 'phone_request':
                Mail::to($req->email)->send(new PhoneUpdateLinkMail($req, $token));
                break;

            case 'name_dob_request':
            default:
                // Existing behaviour — pass the freshly generated token explicitly
                // so CorrectionLinkMail doesn't rely on stale $req state.
                $req->refresh();
                Mail::to($req->email)->send(new CorrectionLinkMail($req, $token));
                break;
        }

        return response()->json([
            'status'  => true,
            'message' => 'Link sent to user email.',
            'data'    => $req,
        ]);
    }

    // public function reject($id)
    // {
    //     $req = CorrectionRequest::findOrFail($id);

    //     if ($req->status !== 'pending') {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Request already processed.',
    //         ], 422);
    //     }

    //     $req->update([
    //         'status'           => 'rejected',
    //         'token'            => null,
    //         'token_expires_at' => null,
    //         'token_used_at'    => null,
    //         'rejected_at'    => now(),
    //     ]);

    //     return response()->json([
    //         'status'  => true,
    //         'message' => 'Request rejected successfully.',
    //         'data'    => $req,
    //     ]);
    // }
    public function reject(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'rejected_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $req = CorrectionRequest::findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json([
                'status'  => false,
                'message' => 'Request already processed.',
            ], 422);
        }

        $req->update([
            'status'           => 'rejected',
            'token'            => null,
            'token_expires_at' => null,
            'token_used_at'    => null,
            'rejected_at'      => now(),
            'rejected_reason'  => $request->rejected_reason,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Request rejected successfully.',
            'data'    => $req->fresh(),
        ]);
    }
}
