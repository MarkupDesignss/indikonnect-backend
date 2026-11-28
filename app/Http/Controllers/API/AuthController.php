<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\DistributorRegistrationCompletedMail;
use App\Models\Admin;
use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Cache;
use App\Models\RejectedUser;
use App\Models\AdminNotification;
use App\Models\Beneficiary;
use App\Models\DistributorProfile;
use App\Models\UserNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\AirtelSmsService;
use App\Services\NotificationService;
use App\Services\KycVerificationService;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

class AuthController extends Controller
{
    protected AirtelSmsService $airtelSmsService;
    protected NotificationService $notificationService;
    protected KycVerificationService $kyc;
    protected IgniteClient $ignite;

    public function __construct(
        AirtelSmsService $airtelSmsService,
        NotificationService $notificationService,
        KycVerificationService $kyc,
        IgniteClient $ignite
    ) {
        $this->airtelSmsService = $airtelSmsService;
        $this->notificationService = $notificationService;
        $this->kyc = $kyc;
        $this->ignite = $ignite;
    }
    /**
     * Get role ID by account type from database
     */
    private function getRoleIdByAccountType($accountType)
    {
        $role = Role::where('slug', $accountType)->first();

        if (!$role) {
            $role = Role::where('name', $accountType)->first();
        }

        return $role ? $role->id : null;
    }

    /**
     * Assign role to user
     */
    private function assignRoleToUser($user)
    {
        try {
            $roleId = $this->getRoleIdByAccountType($user->account_type);

            if (!$roleId) {
                Log::error("Role not found for account type: {$user->account_type}");
                return false;
            }

            $role = Role::find($roleId);
            if (!$role) {
                Log::error("Role not found with ID: {$roleId}");
                return false;
            }

            RoleUser::updateOrInsert(
                ['user_id' => $user->id],
                [
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            $user->update(['role_id' => $roleId]);
            $user->refresh();

            Log::info("Role assigned to user: {$user->id} - Role ID: {$roleId}");
            return true;
        } catch (\Exception $e) {
            Log::error("Role assignment failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user role
     */
    private function getUserRole($user)
    {
        if ($user->role_id) {
            return Role::find($user->role_id);
        }

        $roleUser = RoleUser::where('user_id', $user->id)->first();
        if ($roleUser) {
            $role = Role::find($roleUser->role_id);
            if ($role) {
                $user->update(['role_id' => $role->id]);
                $user->refresh();
            }
            return $role;
        }
        return null;
    }

    /**
     * Verify temporary token
     */
    private function verifyTempToken($user, $token, $phone)
    {
        return is_string($token) && $token !== ''
            && $user->temp_verification_token
            && hash_equals((string) $user->temp_verification_token, $token)
            && $user->otp_verified_at
            && Carbon::parse($user->otp_verified_at)->gt(now()->subMinutes(30))
            && (string) $user->phone === (string) $phone;
    }

    private function safeUser(User $user): User
    {
        if ($user->relationLoaded('distributorProfile') && $user->distributorProfile) {
            $user->distributorProfile->makeHidden(['encrypted_aadhaar','encrypted_pan','encrypted_bank_account','complete_bank_account','aadhaar_hash','pan_hash']);
        }
        return $user->makeHidden([
            'password', 'remember_token', 'otp', 'otp_expires_at',
            'email_otp', 'email_otp_expires_at', 'temp_token',
            'temp_verification_token', 'complete_aadhar_card', 'complete_pan_card',
        ]);
    }

    private function limited(Request $request, string $purpose, string $identifier, int $max = 5): bool
    {
        $keys = [
            'auth:'.$purpose.':id:'.hash('sha256', strtolower(trim($identifier))),
            'auth:'.$purpose.':ip:'.hash('sha256', (string) $request->ip()),
        ];
        foreach ($keys as $i => $key) {
            if (RateLimiter::tooManyAttempts($key, $i === 0 ? $max : $max * 10)) return true;
        }
        foreach ($keys as $key) RateLimiter::hit($key, 600);
        return false;
    }

    private function registrationAccess(Request $request, User $user): bool
    {
        if ($user->is_registered || $user->account_type === 'admin') return false;
        $token = $request->input('temp_token') ?? $request->header('X-Registration-Token');
        if (!is_string($token) || $token === '') return false;
        $key = 'onboarding:'.hash('sha256', $token);
        $proof = Cache::get($key);
        return is_array($proof) && (int) ($proof['id'] ?? 0) === (int) $user->id
            && ($proof['phone'] ?? null) === (string) $user->phone
            && ($proof['email'] ?? null) === (string) $user->email
            && !empty($proof['phone_ok']) && !empty($proof['email_ok']);
    }

    private function registrationDenied()
    {
        return response()->json(['status' => false, 'message' => 'Verify phone and email and provide your registration token.'], 403);
    }
    /**
     * Generate token for existing user
     */
    private function generateTokenForExistingUser($user)
    {
        if ($user->is_active == 0) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is blocked by admin. Please contact support.'
            ], 422);
        }

        if ($user->account_type === 'distributor' && $user->distributor_status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'Your distributor account is not active yet. Please wait for admin .'
            ], 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $refreshToken = Str::random(100);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(7),
            'last_used_at' => now()
        ]);

        $role = $this->getUserRole($user);
        $distributorProfile = null;
        if ($user->account_type === 'distributor') {
            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();
        }

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => 3600,
            'refresh_token' => $refreshToken,
            'user' => $this->safeUser($user),
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug
            ] : null,
            'distributor_profile' => $distributorProfile
        ]);
    }

    /**
     * Send a real SMS through the existing Airtel service.
     * Default: preserve the supplied number. Set services.airtel.otp_phone_format
     * only after confirming the provider's required format (as_received, e164,
     * national, country_digits). Never expose OTPs, phones or credentials in logs.
     */
    private function deliverSmsOtp(string $phone, string $otp, string $purpose): bool
    {
        $phone = trim($phone);
        $format = (string) config('services.airtel.otp_phone_format', 'as_received');
        $destination = $phone;

        // Convert only recognizable Indian mobile numbers, only for SMS delivery.
        // Do not change stored phone values or the user lookup logic.
        if (preg_match('/^(?:\+?91)?([6-9][0-9]{9})$/D', $phone, $matches)) {
            $national = $matches[1];
            $destination = match ($format) {
                'e164' => '+91' . $national,
                'national' => $national,
                'country_digits' => '91' . $national,
                default => $phone,
            };
        }

        $context = [
            'purpose' => $purpose,
            'phone_format' => str_starts_with($destination, '+') ? 'plus_prefixed' : 'no_plus',
            'phone_length' => strlen($destination),
        ];

        try {
            $sent = $this->airtelSmsService->sendOtp($destination, $otp);
            if (!$sent) {
                Log::warning('[OTP delivery] Airtel returned failure', $context + [
                    'result_type' => get_debug_type($sent),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[OTP delivery] Airtel threw exception', $context + [
                'exception_type' => get_class($e),
                'exception_code' => (int) $e->getCode(),
            ]);
            return false;
        }
    }

    /** Clear only the failed challenge, not a newer OTP or a different account. */
    private function clearFailedSmsOtp(User $user, string $otp): void
    {
        User::where('id', $user->id)
            ->where('otp', $otp)
            ->where('otp_expires_at', $user->getRawOriginal('otp_expires_at'))
            ->update(['otp' => null, 'otp_expires_at' => null]);
    }

    public function sendOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($this->limited($request, 'customer-otp-send', (string) $request->phone, 3)) {
                return response()->json(['status' => false, 'message' => 'Too many requests. Try later.'], 429);
            }
            $user = User::where('phone', $request->phone)->first();
            if ($user && $user->account_type !== 'customer') return response()->json(['status'=>false,'message'=>'Please use the appropriate login or registration flow.'],403);

            if ($user && $user->is_registered == 1 && $user->account_type == 'distributor') {
                return response()->json([
                    'status' => false,
                    'message' => 'This phone number is already registered as a distributor. Please login from the distributor app/screen.',
                    'account_type' => 'distributor',
                    'is_registered' => true,
                    'phone' => $request->phone
                ], 403);
            }

            $otp = (string) random_int(100000, 999999);

            if ($user) {
                $user->update([
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes((int) setting('otp_expiry_minutes', 5)),
                ]);

                $message = $user->account_type == 'distributor'
                    ? 'OTP sent successfully. Please complete your distributor registration.'
                    : 'OTP sent successfully. Please verify to login.';
            } else {
                $user = User::create([
                    'phone' => $request->phone,
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes((int) setting('otp_expiry_minutes', 5)),
                    'is_registered' => 0,
                    'account_type' => 'customer',
                ]);

                $message = 'OTP sent successfully. Please verify to complete registration.';
            }

            if (!$this->deliverSmsOtp((string) $request->phone, $otp, 'customer-send')) {
                $this->clearFailedSmsOtp($user, $otp);
                return response()->json(['status' => false, 'message' => 'Unable to send OTP. Please try again later.'], 503);
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'phone' => $request->phone,
                'is_registered' => $user->is_registered,
                'account_type' => $user->account_type,

            ]);
        } catch (\Exception $e) {
            Log::error('[OTP delivery] Customer OTP processing failed', ['exception_type' => get_class($e)]);

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again later.'
            ], 500);
        }
    }

    /**
     * STEP 2: Verify OTP
    */

    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($this->limited($request, 'customer-otp-verify', (string) $request->phone)) {
                return response()->json(['status' => false, 'message' => 'Too many attempts. Try later.'], 429);
            }
            $user = User::where('phone', $request->phone)->first();
            if ($user && $user->account_type !== 'customer') return response()->json(['status'=>false,'message'=>'Please use the appropriate login or registration flow.'],403);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            if ($user->is_registered && !$user->is_active) {
                return response()->json([
                    'status' => false,
                    'message' => 'Your account is blocked by admin. Please contact support.'
                ], 422);
            }

            if (!$user->otp || !hash_equals((string) $user->otp, (string) $request->otp)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            if (!$user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired. Please request a new OTP.'
                ], 422);
            }

            $consumed = User::where('id', $user->id)->where('otp', $user->otp)
                ->where('otp_expires_at', $user->getRawOriginal('otp_expires_at'))
                ->update(['otp'=>null,'otp_expires_at'=>null,'phone_verified'=>1,'phone_verified_at'=>now()]);
            if ($consumed !== 1) return response()->json(['status'=>false,'message'=>'Invalid or expired OTP.'],422);
            $user->refresh();

            if (
                $user->is_registered == 1 &&
                $user->account_type === 'customer'
            ) {
                $token = $user->createToken('customer-login')->plainTextToken;
                $refreshToken = Str::random(100);

                RefreshToken::create([
                    'user_id' => $user->id,
                    'token' => hash('sha256', $refreshToken),
                    'expires_at' => now()->addDays(7),
                    'last_used_at' => now()
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Login successful',
                    'token' => $token,
                    'expires_in' => 3600,
                    'refresh_token' => $refreshToken,
                    'user' => $this->safeUser($user),
                    'is_registered' => true,
                    'requires_registration' => false,
                ]);
            }

            if ($user->account_type !== 'customer') return $this->registrationDenied();
            $tempToken = Str::random(60);

            $user->update([
                'temp_verification_token' => $tempToken,
                'otp_verified_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'OTP verified successfully. Please complete your registration.',
                'temp_token' => $tempToken,
                'phone' => $user->phone,
                'is_registered' => false,
                'requires_registration' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Verify OTP error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    /**
     * CUSTOMER: Complete Registration - Single step
     */

    public function completeCustomerRegistration(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'temp_token' => 'required|string',
                'full_name' => 'required|string|max:255',
                'email' => 'required|email',
                'country' => 'nullable|string|max:255',
                'password' => 'nullable|string|min:8',
                'terms_condition' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            $emailExists = User::where('email', $request->email)
                ->where('is_registered', 1)
                ->when($user->id, function ($query) use ($user) {
                    return $query->where('id', '!=', $user->id);
                })
                ->exists();

            if ($emailExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email is already registered. Please use a different email.'
                ], 422);
            }

            if (!$this->verifyTempToken($user, $request->temp_token, $request->phone)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid verification token. Please verify OTP again.'
                ], 422);
            }

            if ($user->is_registered || $user->account_type !== 'customer') {
                return response()->json(['status' => false, 'message' => 'Please use the appropriate login flow.'], 403);
            }

            $user->update([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'country' => $request->country,
                'account_type' => 'customer',
                'terms_condition' => $request->terms_condition,
                'phone_verified_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'temp_verification_token' => null,
                'otp_verified_at' => null,
                'is_registered' => 1,
                'distributor_status' => 'active',
                'password' => $request->filled('password') ? Hash::make($request->password) : null
            ]);

            $user->refresh();

            Cache::forget('registration_token_' . $request->phone);

            UserNotificationSetting::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'email_notifications' => true,
                    'order_updates' => true,
                    'payment_alerts' => true,
                    'promotional_emails' => true,
                    'security_alerts' => true,
                ]
            );

            $this->assignRoleToUser($user);

            $token = $user->createToken('auth_token')->plainTextToken;
            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id' => $user->id,
                'token' => hash('sha256', $refreshToken),
                'expires_at' => now()->addDays(7),
                'last_used_at' => now()
            ]);

            $role = $this->getUserRole($user);

            if ($user->phone) {
                try {
                    $this->airtelSmsService->sendWelcomeSms($user->phone, (string) $user->full_name);
                } catch (\Throwable $e) {
                    Log::error('Customer registration SMS failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer account created successfully',
                'token' => $token,
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'user' => $this->safeUser($user),
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null
            ]);
        } catch (\Exception $e) {
            Log::error('Customer registration error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    public function checkUserStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'nullable|min:10|max:15',
                'email' => 'nullable|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            if (empty($request->phone) && empty($request->email)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please provide either phone or email.'
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */
            $user = User::where(function ($query) use ($request) {

                if ($request->filled('phone')) {
                    $query->orWhere('phone', $request->phone);
                }

                if ($request->filled('email')) {
                    $query->orWhere('email', $request->email);
                }
            })->first();

            /*
        |--------------------------------------------------------------------------
        | User Does Not Exist
        |--------------------------------------------------------------------------
        */
            if (!$user) {
                return response()->json([
                    'status' => true,
                    'exists' => false,
                    'message' => 'New user. Proceed with distributor registration.',
                    'current_step' => 1,
                    'next_step' => 1,
                    'user_data' => null
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | BLOCK ALREADY REGISTERED DISTRIBUTOR
        |--------------------------------------------------------------------------
        | If email/phone already belongs to a registered distributor,
        | stop the flow immediately.
        |--------------------------------------------------------------------------
        */
            if (
                $user->account_type === 'distributor' &&
                (int) $user->is_registered === 1
            ) {
                return response()->json([
                    'status' => false,
                    'exists' => true,
                    'is_registered' => true,
                    'message' => 'This email or phone is already registered with a Distributor account. Please login or use a different email.',
                    'role' => $user->role
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | BLOCK ALREADY REGISTERED CUSTOMER
        |--------------------------------------------------------------------------
        */
            if (
                $user->account_type === 'customer' &&
                (int) $user->is_registered === 1
            ) {
                return response()->json([
                    'status' => false,
                    'exists' => true,
                    'is_registered' => true,
                    'message' => 'This email or phone is already registered with a Customer account. Please use a different email.',
                    'role' => $user->role
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | BLOCK OTHER REGISTERED ACCOUNTS
        |--------------------------------------------------------------------------
        */
            if (
                $user->account_type !== 'distributor' &&
                (int) $user->is_registered === 1
            ) {
                $roleName = ucfirst($user->account_type ?? 'Unknown');

                return response()->json([
                    'status' => false,
                    'exists' => true,
                    'is_registered' => true,
                    'message' => "This account is already registered as {$roleName}. Please use a different email or phone.",
                    'role' => $user->role
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Get Distributor Profile
        |--------------------------------------------------------------------------
        */
            if (!$this->registrationAccess($request, $user)) {
                return response()->json(['status'=>true,'exists'=>true,'is_registered'=>false,'message'=>'Verify phone and email to continue.','user_data'=>null]);
            }
            $distributorProfile = DistributorProfile::where(
                'user_id',
                $user->id
            )->first();

            /*
        |--------------------------------------------------------------------------
        | Get Current Registration Step
        |--------------------------------------------------------------------------
        */
            $currentStep = $user->registration_step ?? 1;

            /*
        |--------------------------------------------------------------------------
        | Continue Distributor Registration
        |--------------------------------------------------------------------------
        */
            return response()->json([
                'status' => true,
                'exists' => true,
                'is_registered' => false,
                'message' => 'User found. Continuing distributor registration.',
                'current_step' => $currentStep,
                'next_step' => $currentStep + 1,
                'user_data' => $this->getUserStepData(
                    $user,
                    $distributorProfile,
                    $currentStep
                )
            ]);
        } catch (\Exception $e) {

            Log::error('Check user status error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Get step data for a specific step
     */

    public function getStepData($step, $identifier, Request $request)
    {
        try {
            if (!is_numeric($step) || $step < 1 || $step > 7) {
                return response()->json([
                    'status' => false,
                    'message' => 'Step must be between 1 and 7.'
                ], 422);
            }

            $step = (int) $step;

            $termsConditions = DB::table('settings')
                ->where('key', 'terms_conditions')
                ->value('value');

            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $user = User::where('email', $identifier)->first();
            } else {
                $user = User::where('phone', $identifier)->first();
            }

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Existing email not found, Proceed with registration.'
                ], 200);
            }

            if (!$this->registrationAccess($request, $user) && (int) Auth::id() !== (int) $user->id) return $this->registrationDenied();
            $distributorProfile = DistributorProfile::where('user_id', $user->id)
                ->first();

            $currentStep = (int) ($user->registration_step ?? 0);
            $registrationStatus = $distributorProfile
                ? strtolower((string) ($user->distributor_status ?? 'pending'))
                : 'not_started';

            $allStepsCompleted = $currentStep >= 7;

            $stepData = $this->getUserStepData(
                $user,
                $distributorProfile,
                $step
            );

            $completedSteps = $this->getCompletedSteps(
                $user,
                $distributorProfile
            );

            if ($allStepsCompleted && $registrationStatus === 'active') {
                return response()->json([
                    'status' => true,
                    'message' => 'Registration complete. Proceed to login.',
                    'redirect' => 'login',
                    'step' => $step,
                    'step_data' => $stepData,
                    'completed_steps' => $completedSteps,
                    'registration_status' => 'active',
                    'is_complete' => true,
                    'terms_conditions' => $termsConditions,
                ]);
            }

            if ($allStepsCompleted && $registrationStatus === 'pending') {
                return response()->json([
                    'status' => true,
                    'message' => 'Distributor registration submitted successfully. Please wait for admin approval.',
                    'registration_status' => 'pending',
                    'is_complete' => true,
                    'step' => $step,
                    'step_data' => $stepData,
                    'completed_steps' => $completedSteps,
                    'terms_conditions' => $termsConditions,
                ]);
            }

            if ($allStepsCompleted && !in_array($registrationStatus, ['active', 'pending'])) {
                return response()->json([
                    'status' => false,
                    'message' => "Your distributor registration is {$registrationStatus}. Please contact support.",
                    'registration_status' => $registrationStatus,
                    'is_complete' => true,
                    'step' => $step,
                    'step_data' => $stepData,
                    'completed_steps' => $completedSteps,
                ], 422);
            }

            if ($step > ($currentStep + 1)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete previous steps first.',
                    'current_step' => $currentStep,
                ], 422);
            }

            return response()->json([
                'status' => true,
                'step' => $step,
                'step_data' => $stepData,
                'completed_steps' => $completedSteps,
                'registration_status' => $registrationStatus,
                'is_complete' => false,
                'terms_conditions' => $termsConditions,
            ]);
        } catch (\Exception $e) {
            Log::error('Get step data error: ' . $e->getMessage(), [
                'step' => $step,
                'identifier' => $identifier,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching registration step data.'
            ], 500);
        }
    }

    /**
     * Send OTP for Phone or Email (Before Step 1)
     */
    /**
     * Send OTP for Phone or Email (Before Step 1)
     * Simplified version with better user linking
     */

    public function sendVerificationOtp(Request $request)
    {
        $v = Validator::make($request->all(), [
            'type' => 'required|in:phone,email',
            'phone' => 'required_if:type,phone|nullable|string|min:10|max:15',
            'email' => 'required_if:type,email|nullable|email|max:191',
            'temp_token' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) return response()->json(['status'=>false,'errors'=>$v->errors()],422);
        $type = $request->type;
        $value = trim((string) $request->input($type));
        if ($this->limited($request, 'onboarding-send-'.$type, $value, 3))
            return response()->json(['status'=>false,'message'=>'Too many requests. Try later.'],429);
        $token = $request->input('temp_token') ?: Str::random(64);
        $key = 'onboarding:'.hash('sha256', $token);
        try {
            return Cache::lock($key.':lock', 20)->block(3, function () use ($request, $type, $value, $token, $key) {
                $proof = Cache::get($key);
                if ($request->filled('temp_token') && !is_array($proof)) return $this->registrationDenied();
                $proof = $proof ?? ['id'=>null,'phone'=>null,'email'=>null,'phone_ok'=>false,'email_ok'=>false];
                if (!empty($proof[$type.'_ok']) && $proof[$type] !== $value) return $this->registrationDenied();
                $otp = (string) random_int(100000,999999);
                $proof[$type] = $value;
                $proof[$type.'_ok'] = false;
                $proof[$type.'_otp'] = Hash::make($otp);
                $proof[$type.'_expiry'] = now()->addMinutes(5)->timestamp;
                $proof[$type.'_attempts'] = 0;
                if ($type === 'phone') {
                    if (!$this->deliverSmsOtp($value, $otp, 'distributor-onboarding'))
                        return response()->json(['status'=>false,'message'=>'Unable to send OTP.'],503);
                } else {
                    Mail::raw('Your IndieKonnect verification code is '.$otp.'. It expires in 5 minutes.', function ($message) use ($value) {
                        $message->to($value)->subject('IndieKonnect verification code');
                    });
                }
                Cache::put($key,$proof,now()->addMinutes(30));
                return response()->json(['status'=>true,'message'=>ucfirst($type).' OTP sent successfully', $type=>$value,'expires_in'=>300,'temp_token'=>$token]);
            });
        } catch (\Throwable $e) {
            return response()->json(['status'=>false,'message'=>'Unable to send verification code.'],503);
        }
    }

    public function resendOtp(Request $request)
    {
        if ($request->filled('temp_token')) {
            $request->merge(['type'=>'phone']);
            return $this->sendVerificationOtp($request);
        }
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15'
            ]);

            if ($this->limited($request, 'customer-otp-send', (string) $request->phone, 3)) {
                return response()->json(['status' => false, 'message' => 'Too many requests. Try later.'], 429);
            }
            $user = User::where('phone', $request->phone)->first();
            if ($user && $user->account_type !== 'customer') return response()->json(['status'=>false,'message'=>'Please use the appropriate login or registration flow.'],403);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 422);
            }

            if ($user->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already registered. Please login.'
                ], 422);
            }

            $otp = (string) random_int(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => now()->addMinutes(10),
                'temp_verification_token' => null,
                'otp_verified_at' => null
            ]);

            if (!$this->deliverSmsOtp((string) $request->phone, $otp, 'customer-resend')) {
                $this->clearFailedSmsOtp($user, $otp);
                return response()->json(['status' => false, 'message' => 'Unable to send OTP. Please try again later.'], 503);
            }

            return response()->json([
                'status' => true,
                'message' => 'OTP resent successfully',

            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Verify Phone OTP
     */
    public function verifyPhoneOtp(Request $request)
    {
        return $this->verifyOnboardingOtp($request, 'phone');
    }

    private function verifyOnboardingOtp(Request $request, string $type)
    {
        $v=Validator::make($request->all(),[
            $type => $type === 'phone' ? 'required|string|min:10|max:15' : 'required|email|max:191',
            'otp'=>'required|digits:6','temp_token'=>'required|string|max:128',
        ]);
        if ($v->fails()) return response()->json(['status'=>false,'errors'=>$v->errors()],422);
        $value=trim((string)$request->input($type));
        if ($this->limited($request,'onboarding-verify-'.$type,$value)) return response()->json(['status'=>false,'message'=>'Too many attempts. Try later.'],429);
        $token=$request->temp_token;
        $key='onboarding:'.hash('sha256',$token);
        try {
            return Cache::lock($key.':lock',20)->block(3,function() use($request,$type,$value,$token,$key) {
                $proof=Cache::get($key);
                if (!is_array($proof) || ($proof[$type]??null)!==$value || empty($proof[$type.'_otp']) || ($proof[$type.'_expiry']??0)<now()->timestamp || ($proof[$type.'_attempts']??0)>=5)
                    return response()->json(['status'=>false,'message'=>'Invalid or expired OTP.'],422);
                $proof[$type.'_attempts']++;
                if (!Hash::check((string)$request->otp,$proof[$type.'_otp'])) {
                    Cache::put($key,$proof,now()->addMinutes(30));
                    return response()->json(['status'=>false,'message'=>'Invalid or expired OTP.'],422);
                }
                unset($proof[$type.'_otp'],$proof[$type.'_expiry']);
                $proof[$type.'_ok']=true;
                $user=null;
                if (!empty($proof['phone_ok']) && !empty($proof['email_ok'])) {
                    $user=DB::transaction(function() use($proof) {
                        $phone=User::where('phone',$proof['phone'])->lockForUpdate()->get();
                        $email=User::where('email',$proof['email'])->lockForUpdate()->get();
                        $matches=$phone->concat($email)->unique('id')->values();
                        if ($matches->count()>1) return null;
                        $user=$matches->first();
                        if ($user && ($user->is_registered || !in_array($user->account_type,['customer','distributor'],true)
                            || ($user->phone && (string)$user->phone!==$proof['phone'])
                            || ($user->email && strcasecmp((string)$user->email,$proof['email'])!==0))) return null;
                        if (!$user) $user=new User(['account_type'=>'customer','is_registered'=>0,'registration_step'=>0]);
                        $user->fill(['phone'=>$proof['phone'],'email'=>$proof['email'],'phone_verified'=>1,'phone_verified_at'=>now(),'email_verified_at'=>now()]);
                        $user->save();
                        return $user;
                    });
                    if (!$user) { Cache::forget($key); return response()->json(['status'=>false,'message'=>'Account conflict. Please contact support.'],409); }
                    $proof['id']=$user->id;
                }
                Cache::put($key,$proof,now()->addMinutes(30));
                return response()->json(['status'=>true,'message'=>ucfirst($type).' verified successfully.',$type.'_verified'=>true,'temp_token'=>$token,'user'=>$user?$this->safeUser($user):null]);
            });
        } catch (\Throwable $e) { return response()->json(['status'=>false,'message'=>'Unable to verify OTP.'],503); }
    }

    /**
     * Verify Email OTP
     */
    public function verifyEmailOtp(Request $request)
    {
        return $this->verifyOnboardingOtp($request, 'email');
    }

    /**
     * DISTRIBUTOR: Step 1 - Personal Info (After Phone & Email Verification)
     */

    public function distributorStep1Personal(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->where(function ($query) {
                        return $query->where('is_registered', 1);
                    })
                ],
                'full_name' => 'required|string|max:255',

                'account_type' => 'nullable',
                'country' => 'nullable|string|max:255',
                'password' => 'nullable|string|min:8',
                'date_of_birth' => 'required|date|before:-18 years',
                'terms_condition' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please verify your phone first.'
                ], 422);
            }

            if (!$user->phone_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please verify your phone number first.'
                ], 422);
            }

            if (!$user->email_verified_at) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please verify your email first.'
                ], 422);
            }

            $existingEmail = User::where('email', $request->email)
                ->where('is_registered', 1)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingEmail) {
                return response()->json([
                    'status' => false,
                    'message' => 'This email is already registered with another account.'
                ], 422);
            }

            if (strcasecmp((string) $user->email, (string) $request->email) !== 0) return $this->registrationDenied();

            $user->update([
                'full_name' => $request->full_name,
                'email' => $user->email,
                'country' => $request->country,

                'account_type' => 'distributor',
                'terms_condition' => $request->terms_condition,
                'date_of_birth' => $request->date_of_birth,
                'password' => $request->filled('password')
                    ? Hash::make($request->password)
                    : $user->password,
                'is_registered' => 0,
                'registration_step' => max($user->registration_step ?? 0, 1),
            ]);

            $businessProfile = DistributorProfile::where('user_id', $user->id)->first();
            if (!$businessProfile) {
                $businessProfile = DistributorProfile::create([
                    'user_id' => $user->id,
                    'kyc_status' => 'pending',

                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Personal information saved successfully',
                'step' => 1,
                'next_step' => 2,
                'user' => $this->safeUser($user),
                'phone_verified' => true,
                'email_verified' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 1 error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    public function checkDistributorAndGenerateSponsor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'distributor_id'    => 'required|exists:users,distributor_id',
                'custom_sponsor_id' => 'nullable|max:20|unique:users,sponsor_id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $distributor = User::where('distributor_id', $request->distributor_id)->first();

            if (!$distributor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Distributor not found.'
                ], 422);
            }

            if ($request->filled('custom_sponsor_id')) {
                $exists = User::where('sponsor_id', $request->custom_sponsor_id)->exists();

                if ($exists) {
                    return response()->json([
                        'status'               => false,
                        'message'              => 'This Sponsor ID is already taken. Please choose another one.',
                        'suggested_sponsor_id' => $this->generateUniqueSponsorId(),
                    ], 422);
                }

                return response()->json([
                    'status'      => true,
                    'message'     => 'Distributor verified and custom Sponsor ID is available.',
                    'distributor' => [
                        'id'             => $distributor->id,
                        'distributor_id' => $distributor->distributor_id,
                        'full_name'      => $distributor->full_name,
                    ],
                    'sponsor_id'  => $request->custom_sponsor_id,
                    'is_custom'   => true,
                ]);
            }

            $sponsorId = $this->generateUniqueSponsorId();

            return response()->json([
                'status'      => true,
                'message'     => 'Distributor verified and Sponsor ID generated.',
                'distributor' => [
                    'id'             => $distributor->id,
                    'distributor_id' => $distributor->distributor_id,
                    'full_name'      => $distributor->full_name,
                ],
                'sponsor_id'  => $sponsorId,
                'is_custom'   => false,
            ]);
        } catch (\Exception $e) {
            Log::error('Check distributor error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Generate a unique sponsor_id.
     * Format: SP + 8 uppercase alphanumeric chars
     */
    private function generateUniqueSponsorId()
    {
        do {
            $sponsorId = 'SP' . strtoupper(Str::random(8));
        } while (User::where('sponsor_id', $sponsorId)->exists());

        return $sponsorId;
    }

    /**
     * DISTRIBUTOR: Step 2 - Sponsor & Placement
     */

    public function distributorStep2Sponsor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone'         => 'required|min:10|max:15',
                'ba_id'         => 'nullable|string|max:50',
                'sponsor_id'    => 'required|string|max:50',
                'placement_leg' => 'nullable|in:left,right',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if (!$user->phone_verified || !$user->email_verified_at) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please complete step 1 first.'
                ], 422);
            }

            $sponsorId = trim($request->sponsor_id);

            try {
                $response = $this->ignite->validateSponsor($sponsorId);
            } catch (\Exception $e) {
                Log::error('[Step2-Sponsor] Ignite validateSponsor threw', [
                    'user_id' => $user->id,
                    'sponsor' => $sponsorId,
                    'error'   => $e->getMessage(),
                ]);

                return response()->json([
                    'status'  => false,
                    'message' => 'Unable to validate sponsor right now. Please try again.',
                ], 503);
            }

            if (($response['code'] ?? null) !== ResultCode::OK->value) {
                Log::warning('[Step2-Sponsor] validateSponsor rejected', [
                    'user_id' => $user->id,
                    'sponsor' => $sponsorId,
                    'code'    => $response['code'] ?? null,
                    'msg'     => $response['msg'] ?? null,
                ]);

                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid sponsor ID: ' . ($response['msg'] ?? 'Not found'),
                    'code'    => $response['code'] ?? null,
                ], 422);
            }

            $sponsorData = $response['data'] ?? [];

            $updateData = [
                'sponsor_id'        => $sponsorId,
                'placement_leg'     => $request->placement_leg,
                'registration_step' => max($user->registration_step ?? 0, 2),
            ];

            if ($request->filled('ba_id')) {
                $updateData['distributor_id'] = trim($request->ba_id);
            }

            $user->update($updateData);

            return response()->json([
                'status'    => true,
                'message'   => 'Sponsor validated and saved.',
                'step'      => 2,
                'next_step' => 3,
                'sponsor'   => [
                    'ba_id'      => $sponsorData['ba_id']      ?? $sponsorId,
                    'member_id'  => $sponsorData['member_id']  ?? null,
                    'full_name'  => $sponsorData['name']       ?? null,
                    'plan'       => $sponsorData['plan']       ?? null,
                ],
                'user' => $this->safeUser($user->fresh()),
            ]);
        } catch (\Exception $e) {
            Log::error('[Step2-Sponsor] Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 3 - Aadhaar Verification
     */

    /**
     * DISTRIBUTOR: Step 3 - Aadhaar Verification (OTP based)
     *
     * Flow:
     *   1. First call: send Aadhaar number  => returns reference_id
     *   2. Second call: send reference_id + OTP => marks Aadhaar as verified
     */
    public function distributorStep3Aadhaar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone'             => 'required|min:10|max:15',
                'encrypted_aadhaar' => 'required|string|size:12',
                'aadhaar_consent'   => 'required|in:0,1',
                'reference_id'      => 'nullable|string',
                'otp'               => 'nullable|digits:6',
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {

                return response()->json([
                    'status'  => false,
                    'message' => 'User not found.',
                ], 422);
            }

            if ((int) $request->aadhaar_consent !== 1) return response()->json(['status'=>false,'message'=>'Aadhaar consent required.'],422);
            $aadhaarNumber = $request->encrypted_aadhaar;

            /*
        |--------------------------------------------------------------------------
        | CASE 1: verify OTP
        |--------------------------------------------------------------------------
        */
            if ($request->filled('reference_id') && $request->filled('otp')) {

                $refKey = 'aadhaar:'. $user->id . ':' . hash('sha256', (string) $request->reference_id);
                $expected = Cache::get($refKey);
                if (!is_string($expected) || !hash_equals($expected, hash_hmac('sha256', $aadhaarNumber, (string) config('app.key')))) return response()->json(['status'=>false,'message'=>'Invalid or expired Aadhaar verification session.'],422);
                $result = $this->kyc->verifyAadhaarOtp(
                    $request->reference_id,
                    $request->otp
                );

                if (!$result['success']) {

                    return response()->json([
                        'status'  => false,
                        'message' => $result['data']['message'] ?? 'Aadhaar OTP verification failed.',
                    ], 422);
                }

                Cache::forget($refKey);
                $aadhaarData = $result['data']['data'] ?? [];

                $distributorProfile = DistributorProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'encrypted_aadhaar'   => encrypt($aadhaarNumber),
                        'aadhaar_consent'     => $request->aadhaar_consent,
                        'aadhaar_verified'    => 1,
                        'aadhaar_verified_at' => now(),
                        'kyc_status'          => 'pending',
                    ]
                );

                $user->update([
                    'registration_step'    => max($user->registration_step ?? 0, 3),
                    'aadhaar_last4'        => substr($aadhaarNumber, -4),
                ]);

                $distributorProfile->refresh();
                $user->refresh();

                return response()->json([
                    'status'           => true,
                    'message'          => 'Aadhaar verified successfully.',
                    'step'             => 3,
                    'next_step'        => 4,
                    'aadhaar_verified' => true,
                    'aadhaar_last4'    => '****' . substr($aadhaarNumber, -4),
                    'verified_name'    => $aadhaarData['name'] ?? null,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | CASE 2: send OTP
        |--------------------------------------------------------------------------
        */

            $result = $this->kyc->sendAadhaarOtp($aadhaarNumber);

            if (!$result['success']) {

                return response()->json([
                    'status'  => false,
                    'message' => $result['data']['message'] ?? 'Failed to send Aadhaar OTP.',
                ], 422);
            }

            $referenceId = $result['data']['data']['reference_id'] ?? null;
            if (!$referenceId) return response()->json(['status'=>false,'message'=>'Missing verification reference.'],503);
            Cache::put('aadhaar:'.$user->id.':'.hash('sha256',(string)$referenceId),hash_hmac('sha256',$aadhaarNumber,(string)config('app.key')),now()->addMinutes(10));

            DistributorProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'encrypted_aadhaar' => encrypt($aadhaarNumber),
                    'aadhaar_verified' => 0,
                    'aadhaar_verified_at' => null,
                    'aadhaar_consent'   => $request->aadhaar_consent,
                    'kyc_status'        => 'pending',
                ]
            );

            return response()->json([
                'status'       => true,
                'message'      => 'Aadhaar OTP sent successfully. Please verify.',
                'step'         => 3,
                'reference_id' => $referenceId,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 4 - PAN Verification
     */

    /**
     * DISTRIBUTOR: Step 4 - PAN Verification
     *
     * Uses KycVerificationService to:
     *   1. Verify PAN details (name + DOB)
     *   2. Check PAN-Aadhaar link status (KYC confirmation)
     */
    /**
     * DISTRIBUTOR: Step 4 - PAN Verification
     *
     * Uses KycVerificationService to verify PAN details (name + DOB).
     * Note: Aadhaar-PAN link check is NOT performed here because Aadhaar
     * is already verified via OTP in Step 3.
     */
    public function distributorStep4Pan(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone'           => 'required|min:10|max:15',
                'encrypted_pan'   => 'required|string|size:10',
                'name_as_per_pan' => 'required|string|max:255',
                'date_of_birth'   => 'required|date_format:d/m/Y',
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {

                return response()->json([
                    'status'  => false,
                    'message' => 'User not found.',
                ], 422);
            }

            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();

            if (!$distributorProfile || !$distributorProfile->aadhaar_verified) {

                return response()->json([
                    'status'  => false,
                    'message' => 'Please complete Aadhaar verification first.',
                ], 422);
            }

            $pan = strtoupper($request->encrypted_pan);

            /*
            |--------------------------------------------------------------------------
            | PAN verify
            |--------------------------------------------------------------------------
            */

            $panResult = $this->kyc->verifyPan(
                $pan,
                $request->name_as_per_pan,
                $request->date_of_birth
            );

            if (!$panResult['success']) {

                return response()->json([
                    'status'  => false,
                    'message' => $panResult['data']['message'] ?? 'PAN verification failed.',
                ], 422);
            }

            $panData = $panResult['data']['data'] ?? [];

            if (($panData['status'] ?? '') !== 'valid') {

                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid PAN or name mismatch.',
                    'data'    => $panData,
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Save to DB
            |--------------------------------------------------------------------------
            */
            $distributorProfile->update([
                'encrypted_pan'   => encrypt($pan),
                'pan_verified'    => 1,
                'pan_verified_at' => now(),
                'kyc_status'      => 'pending',
            ]);

            $user->update([
                'registration_step' => max($user->registration_step ?? 0, 4),
                'pan_last4'         => substr($pan, -4),
            ]);

            $distributorProfile->refresh();
            $user->refresh();

            return response()->json([
                'status'       => true,
                'message'      => 'PAN verified successfully.',
                'step'         => 4,
                'next_step'    => 5,
                'pan_verified' => true,
                'pan_last4'    => '****' . substr($pan, -4),
                'pan_name'     => $panData['name_as_per_pan'] ?? null,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 5 - Bank Account Details
     */

    public function distributorStep5Bank(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'bank_holder_name' => 'required|string|max:255',
                'bank_name' => 'required|string|max:255',
                'title' => 'required|string|max:255',
                'type_of_entity' => 'required|string|max:255',
                'branch_name' => 'required|string|max:255',
                'gst_in' => 'nullable|string|max:15',
                'company_name' => 'nullable|string|required_with:gst_in',
                'encrypted_bank_account' => 'required|string|max:50',
                'confirm_account_number' => 'required|string|max:50',
                'bank_ifsc' => 'required|string|max:20',
                'account_type' => 'required|in:current,savings',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->encrypted_bank_account !== $request->confirm_account_number) {
                return response()->json([
                    'status' => false,
                    'message' => 'Account numbers do not match.'
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if ($user->registration_step < 4) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete step 4 (PAN) first.'
                ], 422);
            }

            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();
            if (!$distributorProfile || !$distributorProfile->pan_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete PAN verification first.'
                ], 422);
            }

            $bankVerified     = false;
            $nameAtBank       = null;
            $verificationNote = null;

            try {
                $bankResult = $this->kyc->verifyBankAccountPennyLess(
                    $request->bank_ifsc,
                    $request->encrypted_bank_account
                );

                if ($bankResult['success']) {
                    $bankData      = $bankResult['data']['data'] ?? [];
                    $accountExists = $bankData['account_exists'] ?? null;

                    if ($accountExists === true || $accountExists === null) {
                        $bankVerified = true;
                        $nameAtBank   = $bankData['name_at_bank'] ?? null;
                    } else {
                        $verificationNote = 'API returned account_exists = false';

                    }
                } else {
                    $verificationNote = $bankResult['data']['message']
                        ?? $bankResult['data']['error']['message']
                        ?? 'Bank API verification failed';

                }
            } catch (\Throwable $e) {
                $verificationNote = 'Bank verification service unavailable.';

            }

            $distributorProfile->update([
                'encrypted_bank_account' => encrypt((string) $request->encrypted_bank_account),
                'bank_ifsc'              => $request->bank_ifsc,
                'title'                  => $request->title,
                'type_of_entity'         => $request->type_of_entity,
                'bank_holder_name'       => $request->bank_holder_name,
                'gst_in'                 => $request->gst_in ?? 'URP',
                'company_name'           => $request->company_name,
                'bank_name'              => $request->bank_name,
                'branch_name'            => $request->branch_name,
                'account_type'           => $request->account_type,
                'bank_verified'          => $bankVerified ? 1 : 0,
            ]);

            $user->update([
                'registration_step' => max($user->registration_step ?? 0, 5),
                'account_last4'     => substr($request->encrypted_bank_account, -4),
            ]);

            $message = $bankVerified
                ? 'Bank details verified and saved successfully'
                : 'Bank details saved successfully. Verification will be completed shortly.';

            return response()->json([
                'status'            => true,
                'message'           => $message,
                'step'              => 5,
                'next_step'         => 6,
                'bank_verified'     => $bankVerified,
                'verification_note' => $verificationNote,
                'bank_details'      => [
                    'bank_name'        => $request->bank_name,
                    'title'            => $request->title,
                    'bank_holder_name' => $request->bank_holder_name,
                    'account_last4'    => '****' . substr($request->encrypted_bank_account, -4),
                    'bank_ifsc'        => $request->bank_ifsc,
                    'verified_name'    => $nameAtBank,
                ],
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 6 - Location Consent
     */
    public function distributorStep6Location(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'location_consent' => 'required|in:0,1',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if ($user->registration_step < 5) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete step 5 (Bank) first.'
                ], 422);
            }

            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();
            if ($distributorProfile) {
                $distributorProfile->update([
                    'location_consent' => $request->location_consent,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'location_consent_at' => $request->location_consent == 1 ? now() : null
                ]);
            }

            $user->update([
                'registration_step' => max($user->registration_step ?? 0, 6),
                'location_consent_given' => $request->location_consent
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Location consent saved successfully',
                'step' => 6,
                'next_step' => 7,
                'location_consent' => $request->location_consent == 1
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 6 location error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Case-insensitive, spelling-exact match against settings value.
     */
    private function matchesSettingText(string $input, string $expected): bool
    {
        $input    = trim($input);
        $expected = trim($expected);

        return mb_strtolower($input) === mb_strtolower($expected);
    }
    /**
     * DISTRIBUTOR: Step 7 - Review & Submit
     */
    public function distributorStep7Submit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10',
                'accept_terms' => 'required|in:0,1',
                'accept_agreement' => 'required|in:0,1',
                'accept_code_of_conduct' => 'required|in:0,1',
                'accept_terms_text' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Match acceptance text with settings (case-insensitive, spelling exact)
            |--------------------------------------------------------------------------
            */

            /*
            |--------------------------------------------------------------------------
            | Check All Acceptances
            |--------------------------------------------------------------------------
            */
            if (
                $request->accept_terms != 1 ||
                $request->accept_agreement != 1 ||
                $request->accept_code_of_conduct != 1
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please accept all terms and conditions.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Find User By Phone OR Email
            |--------------------------------------------------------------------------
            | Request will contain only `phone`.
            | Example:
            | phone = 9876543210
            | OR
            | phone = user@example.com
            |--------------------------------------------------------------------------
            */
            $user = User::where('phone', $request->phone)
                ->orWhere('email', $request->phone)
                ->first();

            if (!$user || !$this->registrationAccess($request, $user)) return $this->registrationDenied();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Step 6 Completion
            |--------------------------------------------------------------------------
            */
            if (($user->registration_step ?? 0) < 6) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete step 6 (Location) first.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Distributor Profile
            |--------------------------------------------------------------------------
            */
            $distributorProfile = DistributorProfile::where(
                'user_id',
                $user->id
            )->first();

            if (!$distributorProfile) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete all previous steps.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Phone & Email
            |--------------------------------------------------------------------------
            */
            if (!$user->phone_verified || !$user->email_verified_at) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone and email verification required.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Aadhaar
            |--------------------------------------------------------------------------
            */
            if (!$distributorProfile->aadhaar_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Aadhaar verification required.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify PAN
            |--------------------------------------------------------------------------
            */
            if (!$distributorProfile->pan_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'PAN verification required.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Bank Details
            |--------------------------------------------------------------------------
            */
            if (empty($distributorProfile->encrypted_bank_account)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Bank details required.'
                ], 422);
            }

            /* ============ SPONSOR CHECK ============ */
            if (empty($user->sponsor_id)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Sponsor is required. Please go back to Step 2.',
                ], 422);
            }

            /* ============ IGNITE: Register Member ============ */
            if (empty($user->distributor_id)) {
                $partnerRef = 'IDK-' . $user->id . '-' . time();

                try {
                    $igniteResponse = $this->ignite->registerMember(
                        partnerMemberRef: $partnerRef,
                        sponsorBaId: $user->sponsor_id,
                        fullname: $user->full_name,
                    );
                } catch (\Exception $e) {
                    Log::error('[Step7-Submit] Ignite call failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);

                    return response()->json([
                        'status'  => false,
                        'message' => 'Unable to register with Ignite right now. Please try again.',
                    ], 503);
                }

                if ($igniteResponse['code'] === ResultCode::ORDER_DUPLICATE->value) {
                    $igniteResponse = $this->ignite->getOrder($partnerRef);
                }

                if ($igniteResponse['code'] !== ResultCode::OK->value) {
                    Log::error('[Step7-Submit] Ignite error', [
                        'user_id' => $user->id,
                        'code'    => $igniteResponse['code'],
                        'msg'     => $igniteResponse['msg'],
                    ]);

                    return response()->json([
                        'status'  => false,
                        'message' => 'Ignite registration failed: ' . ($igniteResponse['msg'] ?? ''),
                        'code'    => $igniteResponse['code'],
                    ], 422);
                }

                $igniteData = $igniteResponse['data'];

                $user->update([
                    'distributor_id' => $igniteData['ba_id'],
                ]);

                Log::info('[Step7-Submit] Ignite member registered', [
                    'user_id'   => $user->id,
                    'ba_id'     => $igniteData['ba_id'],
                    'member_id' => $igniteData['member_id'],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Finalize Registration
            |--------------------------------------------------------------------------
            */
            $user->update([
                'is_registered' => 1,
                'distributor_status' => 'pending',
                'registration_step' => max(
                    $user->registration_step ?? 0,
                    7
                ),
                'registration_completed_at' => now(),

                'otp' => null,
                'otp_expires_at' => null,
                'email_otp' => null,
                'email_otp_expires_at' => null,

                'accept_terms' => $request->accept_terms,
                'accept_agreement' => $request->accept_agreement,
                'accept_code_of_conduct' => $request->accept_code_of_conduct,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update Distributor Profile
            |--------------------------------------------------------------------------
            */
            $distributorProfile->update([
                'registration_completed' => 1,
                'submitted_at' => now(),
                'terms_accepted_at' => now(),
                'kyc_status' => 'pending',
                'application_status' => 'submitted',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Assign Distributor Role
            |--------------------------------------------------------------------------
            */
            $this->assignRoleToUser($user);

            /*
            |--------------------------------------------------------------------------
            | Send Notification To Admin
            |--------------------------------------------------------------------------
            */
            $admin = DB::table('admins')->first();

            if ($admin) {
                DB::table('admin_notifications')->insert([
                    'admin_id' => $admin->id ?? 1,
                    'type' => 'new_distributor_registration',
                    'title' => 'New Distributor Registration',
                    'message' => "{$user->full_name} has completed distributor registration.",
                    'reference_type' => 'user',
                    'reference_id' => $user->id,
                    'priority' => 'high',

                    'extra_data' => json_encode([
                        'distributor_id' => $user->id,
                        'name' => $user->full_name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'distributor_profile' => $distributorProfile,
                    ]),

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | User Notification Settings
            |--------------------------------------------------------------------------
            */
            UserNotificationSetting::updateOrCreate(
                [
                    'user_id' => $user->id
                ],
                [
                    'email_notifications' => true,
                    'order_updates' => true,
                    'payment_alerts' => true,
                    'promotional_emails' => true,
                    'security_alerts' => true,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Send User Notification
            |--------------------------------------------------------------------------
            */
            try {
                $this->notificationService->sendUserNotification(
                    $user,
                    'distributor_registration_completed',
                    [
                        'title' => 'Registration Submitted Successfully',

                        'message' =>
                        'Your distributor registration has been submitted successfully. ' .
                            'We will notify you once the KYC review is complete.',

                        'customer_name' =>
                        $user->full_name ??
                            $user->name ??
                            'Distributor',

                        'phone' => $user->phone,
                        'email' => $user->email,

                        'submitted_at' =>
                        now()->format('d M Y, h:i A'),

                        'url' =>
                        rtrim(
                            config(
                                'app.frontend_url',
                                config('app.url')
                            ),
                            '/'
                        ) . '/dashboard',
                    ],
                    ['database', 'mail']
                );
            } catch (\Throwable $e) {

                Log::error(
                    'Distributor registration notification failed',
                    [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            /* =============================================
        | SEND REGISTRATION SUBMITTED SMS (TWILIO)
        ============================================= */
            $phone = $user->phone;

            /*
            |--------------------------------------------------------------------------
            | Clear Registration Token
            |--------------------------------------------------------------------------
            */
            Cache::forget(
                'registration_token_' . $request->phone
            );

            /*
            |--------------------------------------------------------------------------
            | Success Response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'status' => true,
                'message' =>
                'Distributor registration submitted successfully. ' .
                    'Please wait for admin approval.',

                'user' => $this->safeUser($user),

                'distributor_profile' => $distributorProfile,

                'registration_status' => 'pending_approval',
            ]);
        } catch (\Exception $e) {

            Log::error(
                'Distributor step 7 submit error: ' . $e->getMessage()
            );

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Distributor Login with Email and Password
     */

    public function distributorLogin(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email'          => 'nullable|required_without:distributor_id|email',
                'distributor_id' => 'nullable|required_without:email|string',
                'password'       => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            /*
             * Build the base query — only distributor accounts.
             */
            if ($this->limited($request,'distributor-login',(string)($request->email ?? $request->distributor_id), 8)) return response()->json(['status'=>false,'message'=>'Too many attempts. Try later.'],429);
            $userQuery = User::where('account_type', 'distributor');

            /*
             * Determine which credential was supplied.
             * (Both could technically be sent — email takes priority,
             *  but you can also reject that case if you want strictness.)
             */
            if ($request->filled('email')) {
                $userQuery->where('email', $request->email);
                $loginType = 'email';
            } else {
                $userQuery->where('distributor_id', $request->distributor_id);
                $loginType = 'distributor_id';
            }

            $user = $userQuery->first();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => $loginType === 'email'
                        ? 'No distributor account found with this email.'
                        : 'No distributor account found with this distributor ID.',
                ], 422);
            }

            if ($user->is_registered == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please complete your registration first.',
                ], 422);
            }

            if (empty($user->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Password not set. Please use OTP login or reset your password.',
                ], 422);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid password.',
                ], 422);
            }

            if ($user->is_active == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is blocked by admin. Please contact support.',
                ], 422);
            }

            $token        = $user->createToken('distributor-auth')->plainTextToken;
            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id'      => $user->id,
                'token'        => hash('sha256', $refreshToken),
                'expires_at'   => now()->addDays(7),
                'last_used_at' => now(),
            ]);

            $role = $this->getUserRole($user);

            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();

            Log::info('Distributor login', [
                'user_id'    => $user->id,
                'login_via'  => $loginType,
                'ip'         => $request->ip(),
            ]);

            return response()->json([
                'status'        => true,
                'message'       => 'Distributor login successful',
                'token'         => $token,
                'expires_in'    => 3600,
                'refresh_token' => $refreshToken,
                'user'          => [
                    'id'                 => $user->id,
                    'full_name'          => $user->full_name,
                    'email'              => $user->email,
                    'phone'              => $user->phone,
                    'distributor_id'     => $user->distributor_id,
                    'account_type'       => $user->account_type,
                    'distributor_status' => $user->distributor_status,
                    'profile_picture'    => $user->profile_picture
                        ? asset('storage/' . $user->profile_picture)
                        : null,
                ],
                'role' => $role ? [
                    'id'   => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ] : null,
                'distributor_profile' => $distributorProfile ? [
                    'id'                     => $distributorProfile->id,
                    'kyc_status'             => $distributorProfile->kyc_status,
                    'bank_name'              => $distributorProfile->bank_name,
                    'bank_holder_name'       => $distributorProfile->bank_holder_name,
                    'bank_ifsc'              => $distributorProfile->bank_ifsc,
                    'aadhaar_verified'       => $distributorProfile->aadhaar_verified,
                    'pan_verified'           => $distributorProfile->pan_verified,
                    'registration_completed' => $distributorProfile->registration_completed,
                ] : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor login error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * Get distributor registration progress
     */
    public function getDistributorProgress(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if (!$this->registrationAccess($request, $user) && (int) Auth::id() !== (int) $user->id) return $this->registrationDenied();
            $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();

            return response()->json([
                'status' => true,
                'registration_step' => $user->registration_step ?? 0,
                'completed_steps' => [
                    'personal_info' => !empty($user->full_name) && !empty($user->email),
                    'phone_verified' => $user->phone_verified ?? false,
                    'email_verified' => !empty($user->email_verified_at),
                    'sponsor' => !empty($user->placement_leg),
                    'aadhaar_verified' => $distributorProfile && $distributorProfile->aadhaar_verified ?? false,
                    'pan_verified' => $distributorProfile && $distributorProfile->pan_verified ?? false,
                    'bank_details' => $distributorProfile && !empty($distributorProfile->encrypted_bank_account),
                    'location_consent' => $user->location_consent_given ?? false,
                ],
                'is_registered' => $user->is_registered,
                'distributor_status' => $user->distributor_status,
                'distributor_profile' => $distributorProfile
            ]);
        } catch (\Exception $e) {
            Log::error('Get distributor progress error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Get user step data for response
     */
    private function getUserStepData($user, $distributorProfile, $step)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'distributor_id' => $user->distributor_id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'country' => $user->country,
                'date_of_birth' => $user->date_of_birth,
                'account_type' => $user->account_type,
                'registration_step' => $user->registration_step,
                'is_registered' => $user->is_registered,
                'distributor_status' => $user->distributor_status,
                'phone_verified' => $user->phone_verified,
                'phone_verified_at' => $user->phone_verified_at,
                'email_verified_at' => $user->email_verified_at,
                'sponsor_id' => $user->sponsor_id,
                'placement_leg' => $user->placement_leg,
                'location_consent_given' => $user->location_consent_given,
                'aadhaar_last4' => $user->aadhaar_last4 ? '****' . $user->aadhaar_last4 : null,
                'pan_last4' => $user->pan_last4 ? '****' . $user->pan_last4 : null,
                'account_last4' => $user->account_last4 ? '****' . $user->account_last4 : null,
            ]
        ];

        if ($distributorProfile) {
            $data['distributor_profile'] = [
                'id' => $distributorProfile->id,
                'kyc_status' => $distributorProfile->kyc_status,
                'aadhaar_verified' => $distributorProfile->aadhaar_verified,
                'aadhaar_verified_at' => $distributorProfile->aadhaar_verified_at,
                'pan_verified' => $distributorProfile->pan_verified,
                'pan_verified_at' => $distributorProfile->pan_verified_at,
                'type_of_entity' => $distributorProfile->type_of_entity,
                'title' => $distributorProfile->title,
                'bank_verified' => $distributorProfile->bank_verified,
                'bank_name' => $distributorProfile->bank_name,
                'bank_holder_name' => $distributorProfile->bank_holder_name,
                'bank_ifsc' => $distributorProfile->bank_ifsc,
                'branch_name' => $distributorProfile->branch_name,
                'account_type' => $distributorProfile->account_type,
                'location_consent' => $distributorProfile->location_consent,
                'latitude' => $distributorProfile->latitude,
                'longitude' => $distributorProfile->longitude,
                'registration_completed' => $distributorProfile->registration_completed,
            ];
        }

        return $data;
    }

    /**
     * Get completed steps status
     */
    private function getCompletedSteps($user, $distributorProfile)
    {
        return [
            'step_1_personal_info' => !empty($user->full_name) && !empty($user->email) && $user->phone_verified == 1 && !empty($user->email_verified_at),
            'step_2_sponsor' => !empty($user->placement_leg),
            'step_3_aadhaar' => $distributorProfile && $distributorProfile->aadhaar_verified == 1,
            'step_4_pan' => $distributorProfile && $distributorProfile->pan_verified == 1,
            'step_5_bank' => $distributorProfile && !empty($distributorProfile->encrypted_bank_account),
            'step_6_location' => !is_null($user->location_consent_given),
            'step_7_submitted' => $user->is_registered == 1,
        ];
    }
    /**
     * Get Current User with Role
     */
    public function getCurrentUser(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $role = $this->getUserRole($user);

            $distributorProfile = null;
            if ($user->account_type === 'distributor') {
                $distributorProfile = DistributorProfile::where('user_id', $user->id)->first();
            }

            return response()->json([
                'status' => true,
                'user' => $this->safeUser($user),
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null,
                'distributor_profile' => $distributorProfile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $request->user()->currentAccessToken()->delete();
            RefreshToken::where('user_id', $user->id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Refresh Token
     */
    public function refreshToken(Request $request)
    {
        try {
            $request->validate([
                'refresh_token' => 'required'
            ]);

            $hashedToken = hash('sha256', $request->refresh_token);

            $refreshToken = RefreshToken::where('token', $hashedToken)->first();

            if (!$refreshToken) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid refresh token'
                ], 422);
            }

            if ($refreshToken->expires_at->isPast()) {
                $refreshToken->delete();
                return response()->json([
                    'status' => false,
                    'message' => 'Refresh token expired. Please login again.'
                ], 422);
            }

            $user = User::find($refreshToken->user_id);
            if (!$user || !$user->is_registered || !$user->is_active) {
                $refreshToken->delete();
                return response()->json(['status'=>false,'message'=>'Account unavailable.'],403);
            }
            $newAccessToken = $user->createToken('auth_token')->plainTextToken;

            $newRefreshToken = Str::random(100);

            $refreshToken->update([
                'token' => hash('sha256', $newRefreshToken),
                'expires_at' => now()->addDays(7),
                'last_used_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'access_token' => $newAccessToken,
                'expires_in' => 3600,
                'refresh_token' => $newRefreshToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Change Password
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => ['required', 'string'],
                'new_password' => [
                    'required',
                    'confirmed'
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password cannot be same as current password'
                ], 400);
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Password changed successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('changePassword error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }
    /**
     * Get user profile
     */

    private function getCorrectionRejectionDays(): int
    {
        return (int) DB::table('settings')
            ->where('group', 'Correction')
            ->where('key', 'correction_rejection_duation')
            ->value('value');
    }

    public function profile()
    {
        try {

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $user->load([
                'roles',
                'latestCorrectionRequest',
            ]);

            $latestPendingPhoneRequest = \App\Models\CorrectionRequest::where('user_id', $user->id)
                ->where('type', 'phone_request')
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            $latestPendingEmailRequest = \App\Models\CorrectionRequest::where('user_id', $user->id)
                ->where('type', 'email_request')
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            $beneficiaries = Beneficiary::where('user_id', $user->id)
                ->select('id', 'user_id', 'full_name', 'relationship', 'age')
                ->orderBy('created_at', 'desc')
                ->get();

            if (
                $latestPendingEmailRequest &&
                empty($latestPendingEmailRequest->new_email) &&
                !empty($latestPendingEmailRequest->reason) &&
                filter_var($latestPendingEmailRequest->reason, FILTER_VALIDATE_EMAIL)
            ) {
                $latestPendingEmailRequest->new_email = $latestPendingEmailRequest->reason;
                $latestPendingEmailRequest->reason    = 'Email change requested by user';
            }

            if ($user->date_of_birth) {
                $user->date_of_birth = Carbon::parse($user->date_of_birth)
                    ->format('Y-m-d');
            }

            $hasPurchasedMandatoryProduct = DB::table('order_lines')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->where('orders.user_id', $user->id)
                ->where('order_lines.product_id', 32)
                ->whereIn('orders.status', [
                    'confirmed',
                    'dispatched',
                    'partial_dispatched',
                    'shipped',
                    'partial_shipped',
                    'partial_delivered',
                    'delivered',
                ])
                ->whereNull('orders.deleted_at')
                ->exists();

            $hasMandatoryProductInCart = \App\Models\Cart::where('user_id', $user->id)
                ->whereHas('items', function ($query) {
                    $query->where('product_id', 32);
                })
                ->exists();

            $canBuy = (
                $hasPurchasedMandatoryProduct ||
                $hasMandatoryProductInCart
            ) ? 1 : 0;

            $rejectionDays = $this->getCorrectionRejectionDays();

            $nextAllowedAt = null;
            $canResubmit = true;

            $latestCorrection = $user->latestCorrectionRequest;

            if (
                $latestCorrection &&
                $latestCorrection->status === 'rejected' &&
                $latestCorrection->rejected_at
            ) {
                $nextAllowedAt = Carbon::parse($latestCorrection->rejected_at)
                    ->addDays($rejectionDays);

                $canResubmit = now()->greaterThanOrEqualTo($nextAllowedAt);
            }

            if ($user->account_type === 'distributor') {
                $user->load('distributorProfile');

                if (!empty($user->distributor_id)) {
                    $user->distributor_id = preg_replace('/-\d+$/', '', $user->distributor_id);
                }
            }

            if (!empty($user->profile_picture)) {
                $user->profile_picture = asset(
                    'storage/profile_pictures/' . basename($user->profile_picture)
                );
            }

            $extra = [];

            if ($user->account_type === 'distributor') {
                $extra['distributor_link'] =
                    'https://www.markupdesigns.net/indikonnect/distributor/'
                    . $user->distributor_id;
            }

            $buybackActivateRaw = DB::table('settings')
                ->where('key', 'buyback_activate')
                ->value('value');
            $buybackActivate = filter_var($buybackActivateRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $buybackActivate = $buybackActivate ?? false;

            $userArray = $this->safeUser($user)->toArray();

            $userArray['latest_correction_request'] = [
                'phone' => $latestPendingPhoneRequest,
                'email' => $latestPendingEmailRequest,
            ];

            return response()->json([
                'status'  => true,
                'message' => 'Profile fetched successfully',
                'user'    => $userArray,
                'extra'   => $extra,
                'can_buy' => $canBuy,
                'beneficiaries' => $beneficiaries,
                'buyback_activate'          => $buybackActivate,
                'correction_rejection_days' => $rejectionDays,
                'next_allowed_at'           => $nextAllowedAt
                    ? $nextAllowedAt->format('Y-m-d H:i:s')
                    : null,
                'can_resubmit_correction'   => $canResubmit,

            ], 200);
        } catch (\Exception $e) {

            Log::error('Profile API failed.', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * Update Profile - Updated with new BusinessProfile fields
     */

    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) return response()->json(['status'=>false,'message'=>'Unauthorized'],401);
            if ($request->hasAny(['encrypted_bank_account','complete_bank_account','bank_ifsc','bank_holder_name','encrypted_aadhaar','encrypted_pan','complete_pan_card','complete_aadhar_card','password'])
                || ($request->filled('phone') && (string)$request->phone !== (string)$user->phone)
                || ($request->filled('account_type') && $request->account_type !== $user->account_type)) {
                return response()->json(['status'=>false,'message'=>'Use the dedicated verified account or KYC update process.'],422);
            }
            $validator=Validator::make($request->all(),[
                'full_name'=>'nullable|string|max:255','country'=>'nullable|string|max:255',
                'profile_picture'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);
            if ($validator->fails()) return response()->json(['status'=>false,'errors'=>$validator->errors()],422);
            $data=array_filter($request->only(['full_name','country']),fn($value)=>$value!==null);
            if ($request->hasFile('profile_picture')) {
                $old=$user->profile_picture;
                $data['profile_picture']=$request->file('profile_picture')->store('profile_pictures','public');
            }
            $user->update($data);
            if (!empty($old) && $old!==$user->profile_picture) Storage::disk('public')->delete($old);
            $role=$this->getUserRole($user);
            return response()->json([
                'status'=>true,'message'=>'Profile updated successfully',
                'user'=>$this->safeUser($user->fresh()),
                'role'=>$role?['id'=>$role->id,'name'=>$role->name,'slug'=>$role->slug]:null,
                'business_profile'=>$user->account_type==='distributor'?$user->distributorProfile:null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status'=>false,'message'=>'Something went wrong. Please try again.'],500);
        }
    }

    protected function sendAdminNotification(
        string $type,
        string $message,
        int $userId,
        array $extraData = []
    ) {
        try {
            $admin = Admin::first();

            if (!$admin) {
                Log::warning('No admin found in admins table');
                return;
            }

            AdminNotification::create([
                'admin_id' => $admin->id,
                'type' => $type,
                'title' => ucfirst(str_replace('_', ' ', $type)),
                'message' => $message,
                'reference_type' => 'user',
                'reference_id' => $userId,
                'priority' => 'high',
                'extra_data' => json_encode($extraData),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error(
                'Failed to send admin notification: ' . $e->getMessage(),
                [
                    'type' => $type,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Remove Profile Picture
     */
    public function removeProfilePicture()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            if (!$user->profile_picture) {
                return response()->json([
                    'status' => false,
                    'message' => 'No profile picture to remove'
                ], 404);
            }

            if (Storage::disk('public')->exists($user->profile_picture)) {
                Storage::disk('public')->delete($user->profile_picture);
            }

            $user->update([
                'profile_picture' => null
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Profile picture removed successfully',
                'user' => $this->safeUser($user->fresh())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again.'
            ], 500);
        }
    }

    /**
     * Forgot Password - Send OTP
     */
    public function forgotPassword(Request $request)
    {
        $v=Validator::make($request->all(),['email'=>'required|email|max:191']);
        if ($v->fails()) return response()->json(['status'=>false,'errors'=>$v->errors()],422);
        $email=strtolower(trim($request->email));
        if ($this->limited($request,'reset-send',$email,3)) return response()->json(['status'=>false,'message'=>'Too many requests. Try later.'],429);
        $reply=['status'=>true,'message'=>'If the account is eligible, a verification code will be sent to the verified mobile number.'];
        $user=User::where('email',$email)->where('account_type','distributor')->where('is_registered',1)->first();
        if (!$user || !$user->phone || !$user->phone_verified || !$user->phone_verified_at) return response()->json($reply);
        $key='password-reset:'.$user->id;
        try {
            return Cache::lock($key.':lock',20)->block(3,function() use($user,$key,$reply) {
                Cache::forget($key);
                $otp=(string)random_int(100000,999999);
                if (!$this->deliverSmsOtp((string) $user->phone, $otp, 'password-reset')) return response()->json(['status'=>false,'message'=>'Unable to send verification code.'],503);
                Cache::put($key,['otp'=>Hash::make($otp),'attempts'=>0],now()->addMinutes(5));
                return response()->json($reply);
            });
        } catch (\Throwable $e) { return response()->json(['status'=>false,'message'=>'Unable to send verification code.'],503); }
    }
    /**
     * Verify reset password OTP
     */
    public function verifyResetOtp(Request $request)
    {
        $v=Validator::make($request->all(),['email'=>'required|email|max:191','otp'=>'required|digits:6']);
        if ($v->fails()) return response()->json(['status'=>false,'errors'=>$v->errors()],422);
        $email=strtolower(trim($request->email));
        if ($this->limited($request,'reset-verify',$email)) return response()->json(['status'=>false,'message'=>'Too many attempts. Try later.'],429);
        $user=User::where('email',$email)->where('account_type','distributor')->where('is_registered',1)->first();
        if (!$user) return response()->json(['status'=>false,'message'=>'Invalid or expired code.'],422);
        $key='password-reset:'.$user->id;
        try {
            return Cache::lock($key.':lock',20)->block(3,function() use($request,$key) {
                $proof=Cache::get($key);
                if (!is_array($proof) || empty($proof['otp'])) return response()->json(['status'=>false,'message'=>'Invalid or expired code.'],422);
                if (!Hash::check((string)$request->otp,$proof['otp'])) { Cache::forget($key); return response()->json(['status'=>false,'message'=>'Invalid code. Request a new OTP.'],422); }
                $token=Str::random(64);
                Cache::put($key,['token'=>hash('sha256',$token)],now()->addMinutes(10));
                return response()->json(['status'=>true,'message'=>'OTP verified successfully','reset_token'=>$token]);
            });
        } catch (\Throwable $e) { return response()->json(['status'=>false,'message'=>'Unable to verify code.'],503); }
    }
    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $v=Validator::make($request->all(),['email'=>'required|email|max:191','reset_token'=>'required|string|size:64','password'=>['required','string','confirmed',Password::min(8)]]);
        if ($v->fails()) return response()->json(['status'=>false,'errors'=>$v->errors()],422);
        $email=strtolower(trim($request->email));
        if ($this->limited($request,'reset-apply',$email)) return response()->json(['status'=>false,'message'=>'Too many attempts. Try later.'],429);
        $user=User::where('email',$email)->where('account_type','distributor')->where('is_registered',1)->first();
        if (!$user) return response()->json(['status'=>false,'message'=>'Invalid or expired reset token.'],422);
        $key='password-reset:'.$user->id;
        try {
            return Cache::lock($key.':lock',20)->block(3,function() use($request,$user,$key) {
                $proof=Cache::get($key);
                if (!is_array($proof) || empty($proof['token']) || !hash_equals($proof['token'],hash('sha256',$request->reset_token))) return response()->json(['status'=>false,'message'=>'Invalid or expired reset token.'],422);
                Cache::forget($key);
                DB::transaction(function() use($request,$user) {
                    $user->update(['password'=>Hash::make($request->password),'otp'=>null,'otp_expires_at'=>null,'temp_verification_token'=>null,'otp_verified_at'=>null]);
                    $user->tokens()->delete();
                    RefreshToken::where('user_id',$user->id)->delete();
                });
                return response()->json(['status'=>true,'message'=>'Password reset successfully']);
            });
        } catch (\Throwable $e) { return response()->json(['status'=>false,'message'=>'Unable to reset password.'],503); }
    }
    /**
     * Get distributor application status for authenticated user.
     * GET /api/user/application-status
     */
    public function applicationStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        if ($user->account_type === 'customer') {
            return response()->json([
                'status' => true,
                'account_type' => 'customer',
                'application_status' => null,
                'message' => 'You are registered as a customer.'
            ]);
        }

        $profile = DistributorProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json([
                'status' => true,
                'account_type' => 'distributor',
                'application_status' => 'draft',
                'message' => 'Distributor application not found. Please complete registration.'
            ]);
        }

        $status = $profile->application_status ?? 'draft';
        $messages = [
            'draft' => 'Your application is incomplete. Please complete all steps.',
            'submitted' => 'Your application has been submitted and is awaiting review.',
            'under_review' => 'Your application is currently being reviewed by our team.',
            'returned' => 'Your application needs corrections. Please check the reason provided.',
            'approved' => 'Congratulations! Your application is approved. You can now access your distributor dashboard.',
            'rejected' => 'Your application was rejected. Please contact support for more information.'
        ];

        $reviewerName = null;
        if ($profile->reviewed_by) {
            $admin = Admin::find($profile->reviewed_by);
            $reviewerName = $admin ? $admin->name : null;
        }

        $response = [
            'status' => true,
            'account_type' => 'distributor',
            'application_status' => $status,
            'message' => $messages[$status] ?? 'Unknown status',
            'submitted_at' => $profile->submitted_at?->toDateTimeString(),
            'reviewed_at' => $profile->reviewed_at?->toDateTimeString(),
            'reviewed_by' => $reviewerName,
            'rejection_reason' => $profile->rejection_reason,
            'kyc_status' => $profile->kyc_status,
            'is_editable' => in_array($status, ['draft', 'returned']),
            'can_resubmit' => $status === 'returned',
            'is_completed' => in_array($status, ['approved', 'rejected']),
        ];

        if ($status === 'returned' && $profile->rejection_reason) {
            $response['correction_needed'] = $profile->rejection_reason;
        }

        return response()->json($response);
    }
}
