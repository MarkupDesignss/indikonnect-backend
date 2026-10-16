<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CorrectionRequest;

class CorrectionLinkController extends Controller
{
    public function form($token)
    {
        $req = CorrectionRequest::where('token', $token)->first();

        if (!$req || !$req->isValid()) {
            return view('correction.invalid');
        }

        return view('correction.form', compact('req', 'token'));
    }

    // POST /correction/{token}
    public function update(Request $request, $token)
    {
        $req = CorrectionRequest::where('token', $token)->first();

        if (!$req || !$req->isValid()) {
            return view('correction.invalid');
        }

        $data = $request->validate([
            'full_name'     => 'required|string|max:150',
            'date_of_birth' => 'required|date|before:today',
        ]);

        DB::transaction(function () use ($req, $data) {

            $user = User::find($req->user_id);

            if (!$user) {
                abort(404, 'User not found.');
            }

            $user->update([
                'full_name'     => $data['full_name'],
                'date_of_birth' => $data['date_of_birth'],
            ]);

            // Correction request mark as completed
            $req->update([
                'status'        => 'completed',
                'token_used_at' => now(),
            ]);
        });

        return view('correction.success');
    }
}
