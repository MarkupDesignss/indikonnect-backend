<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhoneChangeController extends Controller
{
    // GET: Display the phone number update form
    public function showForm($token)
    {
        $cr = $this->validRequest($token);

        if (!$cr) {
            return view('phone.expired');
        }

        return view('phone.form', compact('token'));
    }

    // POST: Update the phone number
    public function updatePhone(Request $request, $token)
    {
        $cr = $this->validRequest($token);

        if (!$cr) {
            return view('phone.expired');
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:15'],
        ]);

        // Check whether the phone number is already registered to another user
        $taken = User::where('phone', $data['phone'])
            ->where('id', '!=', $cr->user_id)
            ->exists();

        if ($taken) {
            return back()
                ->withErrors([
                    'phone' => 'This phone number is already registered to another user.',
                ])
                ->withInput();
        }

        DB::transaction(function () use ($cr, $data) {
            // Update the user's phone number
            User::where('id', $cr->user_id)->update([
                'phone' => $data['phone'],
            ]);

            // Mark the request as completed and permanently invalidate the token
            $cr->update([
                'phone'         => $data['phone'],
                'status'        => 'completed',
                'token_used_at' => now(),
                'token'         => null,
            ]);
        });

        return view('phone.success');
    }

    // Validate the correction request token
    private function validRequest($token): ?CorrectionRequest
    {
        return CorrectionRequest::where('token', $token)
            ->where('status', 'approved')
            ->whereNull('token_used_at')
            ->where('token_expires_at', '>', now())
            ->first();
    }
}
