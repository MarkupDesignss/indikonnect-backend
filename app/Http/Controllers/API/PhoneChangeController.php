<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhoneChangeController extends Controller
{
    public function showForm($token)
    {
        $cr = $this->validRequest($token);

        if (!$cr) {
            return view('phone.expired');
        }

        return view('phone.form', compact('token'));
    }

    // 🔹 POST: phone update karna
    public function updatePhone(Request $request, $token)
    {
        $cr = $this->validRequest($token);

        if (!$cr) {
            return view('phone.expired');
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:15'],
        ]);

        // 🔥 uniqueness check — kisi aur user ke saath na ho
        $taken = User::where('phone', $data['phone'])
            ->where('id', '!=', $cr->user_id)
            ->exists();

        if ($taken) {
            return back()
                ->withErrors(['phone' => 'Ye phone number already kisi aur user ke paas hai.'])
                ->withInput();
        }

        DB::transaction(function () use ($cr, $data) {
            User::where('id', $cr->user_id)->update(['phone' => $data['phone']]);

            $cr->update([
                'phone'         => $data['phone'],
                'status'        => 'completed',
                'token_used_at' => now(),
                'token'         => null,   // ek baar use = permanent invalidate
            ]);
        });

        return view('phone.success');
    }

    private function validRequest($token): ?CorrectionRequest
    {
        return CorrectionRequest::where('token', $token)
            ->where('status', 'approved')
            ->whereNull('token_used_at')
            ->where('token_expires_at', '>', now())
            ->first();
    }
}
