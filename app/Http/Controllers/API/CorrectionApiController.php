<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\AdminCorrectionMail;
use App\Mail\UserAckMail;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;

class CorrectionApiController extends Controller
{

    public function store(Request $request)
    {
        try {
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

            $pending = CorrectionRequest::where('email', $user->email)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($pending) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You already have a request in progress.',
                ], 422);
            }

            $adminEmail = config('correction.admin_email');

            if (
                empty($adminEmail) ||
                !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
            ) {
                Log::error('Correction request: Admin email is missing or invalid.');

                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to process your request at the moment.',
                ], 500);
            }
            $req = CorrectionRequest::create([
                'user_id'       => $user->id,
                'email'         => $user->email,
                'reason'        => $data['reason'],
                'full_name'     => null,
                'date_of_birth' => null,
                'type'  => 'name_dob_request',
                'status'        => 'pending',
            ]);

            // Send email to admin
            Mail::to($adminEmail)->send(new AdminCorrectionMail($req));

            // Send acknowledgement email to user
            Mail::to($user->email)->send(new UserAckMail($req));

            return response()->json([
                'status'  => true,
                'message' => 'Request sent. Check your email for confirmation.',
                'data'    => $req,
                'fields'  => $req->editable_fields,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Correction request failed.', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while processing your request. Please try again later.',
            ], 500);
        }
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
