<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorrectionLinkController extends Controller
{
    // GET /correction/{token}
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

        $rules = [];

        if ($req->needsNameUpdate()) {
            $rules['full_name'] = 'required|string|max:150';
        }

        if ($req->needsDobUpdate()) {
            $rules['date_of_birth'] = 'required|date|before:today';
        }

        $data = $request->validate($rules);

        DB::transaction(function () use ($req, $data) {

            $user = User::find($req->user_id);
            if (!$user) {
                abort(404, 'User not found.');
            }

            $update = [];

            // ✅ Sirf allowed fields update
            if ($req->needsNameUpdate() && isset($data['full_name'])) {
                $update['full_name'] = $data['full_name'];
            }

            if ($req->needsDobUpdate() && isset($data['date_of_birth'])) {
                $update['date_of_birth'] = $data['date_of_birth'];
            }

            if (!empty($update)) {
                $user->update($update);
            }

            $reqUpdate = [
                'status'        => 'completed',
                'token_used_at' => now(),
            ];

            if ($req->needsNameUpdate() && isset($data['full_name'])) {
                $reqUpdate['full_name'] = $data['full_name'];
            }

            if ($req->needsDobUpdate() && isset($data['date_of_birth'])) {
                $reqUpdate['date_of_birth'] = $data['date_of_birth'];
            }

            $req->update($reqUpdate);
        });

        return view('correction.success');
    }
}
