<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\API\AuthController as APIAuthController;
use App\Http\Controllers\API\SubscriberController;

Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Authentication token is require to access this api.'], 401);
})->name('login');
Route::prefix('admin')->group(function () {
    // Public Routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/send-reset-otp', [AuthController::class, 'sendResetOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/update', [AuthController::class, 'update']);
    });
});
// Admin API Routes
Route::prefix('header')->group(function () {
    Route::get('/', [MenuController::class, 'index']);
    Route::post('/add', [MenuController::class, 'store']);
    Route::delete('/delete/{id}', [MenuController::class, 'destroy']);
    Route::post('/menu/{id}/status', [MenuController::class, 'toggleStatus']);
    Route::post('/update/{id}', [MenuController::class, 'update']);
});
Route::prefix('contents')->group(function () {
    Route::get('/', [ContentController::class, 'index']);
    Route::get('/{slug}', [ContentController::class, 'show']);
    Route::post('/add', [ContentController::class, 'store']);
    Route::post('/update/{id}', [ContentController::class, 'update']);
    Route::delete('/delete/{id}', [ContentController::class, 'destroy']);
});
Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/add', [CategoryController::class, 'store']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::post('/update/{id}', [CategoryController::class, 'update']);
    Route::delete('/delete/{id}', [CategoryController::class, 'destroy']);
    Route::post('/update/{id}/status', [CategoryController::class, 'updateStatus']);
    Route::post('/bulk-delete', [CategoryController::class, 'bulkDelete']);
    Route::delete('/delete-image/{id}', [CategoryController::class, 'deleteImage']);
});


Route::prefix('subscribers')->group(function () {
    Route::get('/', [SubscriberController::class, 'index']);
    Route::post('/', [SubscriberController::class, 'store']);
    Route::delete('/{email}', [SubscriberController::class, 'destroy']);
});

Route::group(['prefix' => 'user'], function () {

    // Public routes (no authentication required)
    Route::post('send-otp', [APIAuthController::class, 'sendOtp'])->name('send-otp');
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('login', [APIAuthController::class, 'login'])->name('login');

    // Password reset routes
    Route::post('forgot-password', [APIAuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('verify-reset-otp', [APIAuthController::class, 'verifyResetOtp'])->name('verify-reset-otp');
    Route::post('reset-password', [APIAuthController::class, 'resetPassword'])->name('reset-password');

    // Refresh token route
    Route::post('refresh-token', [APIAuthController::class, 'refreshToken'])->name('refresh-token');

    // Protected routes (authentication required)
    Route::group(['middleware' => 'auth:api'], function () {
        Route::get('profile', [APIAuthController::class, 'profile'])->name('profile');
        Route::put('profile', [APIAuthController::class, 'updateProfile'])->name('update-profile');
        Route::delete('profile-picture', [APIAuthController::class, 'removeProfilePicture'])->name('remove-profile-picture');
        Route::post('change-password', [APIAuthController::class, 'changePassword'])->name('change-password');
        Route::post('logout', [APIAuthController::class, 'logout'])->name('logout');
    });
});
