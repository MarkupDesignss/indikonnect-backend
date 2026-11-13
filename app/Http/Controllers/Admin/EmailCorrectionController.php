<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmailCorrectionController extends Controller
{
    public function show(string $token)
    {
        $req = $this->findValidRequest($token);

        if (!$req) {
            return view('emails.correction-invalid');
        }

        return view('emails.email-update', compact('req', 'token'));
    }

    public function update(Request $request, string $token)
    {
        $req = $this->findValidRequest($token);

        if (!$req) {
            return view('emails.correction-invalid');
        }

        $validator = Validator::make($request->all(), [
            'new_email' => 'required|email|max:255|unique:users,email',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        DB::transaction(function () use ($req, $request) {
            // Update user email
            $user = $req->user;
            $user->update(['email' => $request->new_email]);

            // Mark request used
            $req->update([
                'new_email'      => $request->new_email,
                'token_used_at'  => now(),
            ]);
        });

        return view('emails.correction-success');
    }

    private function findValidRequest(string $token): ?CorrectionRequest
    {
        $req = CorrectionRequest::with('user')
            ->where('token', $token)
            ->where('type', 'email_request')
            ->whereNull('token_used_at')
            ->first();

        if (!$req || !$req->token_expires_at || $req->token_expires_at->isPast()) {
            return null;
        }

        return $req;
    }
}
