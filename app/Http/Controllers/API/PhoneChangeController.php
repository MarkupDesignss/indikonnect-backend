<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Twilio\Rest\Client;

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

    // POST: Validate phone, send OTP, show OTP form
    public function sendOtp(Request $request, $token)
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

        // Generate 6-digit OTP
        $otp = random_int(100000, 999999);

        // Store pending data in session (keyed by token so multiple tabs don't clash)
        Session::put("phone_otp_{$token}", [
            'phone'      => $data['phone'],
            'otp'        => $otp,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        // Send OTP via Twilio
        try {
            $twilio = new Client(
                env('TWILIO_ACCOUNT_SID'),
                env('TWILIO_AUTH_TOKEN')
            );

            $twilio->messages->create(
                $data['phone'],
                [
                    'from' => env('TWILIO_5R'),
                    'body' => "Your phone verification code is: {$otp}. It expires in 10 minutes.",
                ]
            );
        } catch (\Exception $e) {
            Session::forget("phone_otp_{$token}");

            return back()
                ->withErrors([
                    'phone' => 'Unable to send OTP. Please try again. (' . $e->getMessage() . ')',
                ])
                ->withInput();
        }

        return view('phone.verify-otp', [
            'token' => $token,
            'phone' => $data['phone'],
        ]);
    }

    // POST: Verify OTP and update phone number
    public function verifyOtp(Request $request, $token)
    {
        $cr = $this->validRequest($token);

        if (!$cr) {
            return view('phone.expired');
        }

        $data = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $sessionData = Session::get("phone_otp_{$token}");

        if (!$sessionData) {
            return redirect()->route('phone.form', ['token' => $token])
                ->withErrors(['otp' => 'Your session has expired. Please request a new OTP.']);
        }

        // Check expiry
        if (now()->timestamp > $sessionData['expires_at']) {
            Session::forget("phone_otp_{$token}");

            return redirect()->route('phone.form', ['token' => $token])
                ->withErrors(['otp' => 'OTP has expired. Please request a new one.']);
        }

        // Check OTP match
        if (!hash_equals((string) $sessionData['otp'], (string) $data['otp'])) {
            return back()->withErrors(['otp' => 'Invalid OTP. Please try again.']);
        }

        $phone = $sessionData['phone'];

        // Double-check availability before writing
        $taken = User::where('phone', $phone)
            ->where('id', '!=', $cr->user_id)
            ->exists();

        if ($taken) {
            Session::forget("phone_otp_{$token}");

            return redirect()->route('phone.form', ['token' => $token])
                ->withErrors(['phone' => 'This phone number is already registered to another user.']);
        }

        DB::transaction(function () use ($cr, $phone) {
            User::where('id', $cr->user_id)->update([
                'phone' => $phone,
            ]);

            $cr->update([
                'phone'         => $phone,
                'status'        => 'completed',
                'token_used_at' => now(),
                'token'         => null,
            ]);
        });

        Session::forget("phone_otp_{$token}");

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
