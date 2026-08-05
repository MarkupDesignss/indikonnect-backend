<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\NewSubscriptionMail;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SubscriberController extends Controller
{
    public function index(Request $request)
    {
        try {
            $subscribers = Subscriber::query()->when($request->has('active'), function ($query) use ($request) {
                return $query->where('is_active', $request->boolean('active'));
            })->orderBy('created_at', 'desc')->get();
            return response()->json([
                'success' => true,
                'message' => 'Subscribers retrieved successfully',
                'data' => $subscribers,
                'count' => $subscribers->count()
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subscribers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email|unique:subscribers,email',
            ]);

            $subscriber = Subscriber::create([
                'email' => $validated['email'],
                'is_active' => true,
                'subscribed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $this->sendAdminNotification($subscriber);

            return response()->json([
                'success' => true,
                'message' => 'Successfully subscribed!',
                'data' => $subscriber,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => $e->errors()['email'][0] ?? 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {

            Log::error('Subscription failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Subscription failed. Please try again.',
            ], 500);
        }
    }
    private function sendAdminNotification(Subscriber $subscriber)
    {
        try {
            $adminEmail = config('mail.admin_email', env('ADMIN_MAIL'));

            if (!$adminEmail) {
                Log::warning('ADMIN_MAIL not set in .env file');
                return;
            }
            Mail::to($adminEmail)->send(new NewSubscriptionMail($subscriber));
            Log::info('Admin notification sent to: ' . $adminEmail);
        } catch (\Throwable $th) {
            Log::error('Failed to send admin notification: ' . $th->getMessage());
        }
    }

    public function destroy(string $email)
    {
        try {
            $subscriber = Subscriber::where('email', $email)->first();

            if (!$subscriber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscriber not found'
                ], 404);
            }

            // $subscriber->update(['is_active' => false]);
            $subscriber->delete();

            return response()->json([
                'success' => true,
                'message' => 'Successfully unsubscribed'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unsubscribe',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}