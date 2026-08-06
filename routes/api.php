<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\API\AuthController as APIAuthController;
use App\Http\Controllers\API\ContactController;
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
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/update', [AuthController::class, 'update']);
    });
});

Route::prefix('header')->group(function () {
    // Headers
    Route::get('/', [MenuController::class, 'index']);
    // With middleware
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [MenuController::class, 'store']);
        Route::delete('/delete/{id}', [MenuController::class, 'destroy']);
        Route::post('/menu/{id}/status', [MenuController::class, 'toggleStatus']);
        Route::post('/update/{id}', [MenuController::class, 'update']);
    });
});

// Contents (Pages)
Route::prefix('contents')->group(function () {
    // Public routes (No middleware)
    Route::get('/', [ContentController::class, 'index']);
    Route::get('/{slug}', [ContentController::class, 'show']);
    // Protected routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [ContentController::class, 'store']);
        Route::post('/update/{id}', [ContentController::class, 'update']);
        Route::delete('/delete/{id}', [ContentController::class, 'destroy']);
    });
});


// Categories
Route::prefix('categories')->group(function () {
    // Public routes (No middleware)
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    // Protected routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/add', [CategoryController::class, 'store']);
        Route::post('/update/{id}', [CategoryController::class, 'update']);
        Route::delete('/delete/{id}', [CategoryController::class, 'destroy']);
        Route::post('/update/{id}/status', [CategoryController::class, 'updateStatus']);
        Route::post('/bulk-delete', [CategoryController::class, 'bulkDelete']);
        Route::delete('/delete-image/{id}', [CategoryController::class, 'deleteImage']);
    });
});

// Contact us
Route::prefix('contact')->group(function () {
    Route::post('/', [ContactController::class, 'store']);
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
    });
});

// Newsletters
Route::prefix('subscribers')->group(function () {
    Route::post('/', [SubscriberController::class, 'store']);
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [SubscriberController::class, 'index']);
        Route::delete('/{email}', [SubscriberController::class, 'destroy']);
    });
});

Route::group(['prefix' => 'user'], function () {
    // Public routes (no authentication required)
    Route::post('send-otp', [APIAuthController::class, 'sendOtp']);
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [APIAuthController::class, 'resendOtp']);

    Route::post('login', [APIAuthController::class, 'login']);
    Route::post('verify-login-otp', [APIAuthController::class, 'verifyLoginOtp']);

    Route::post('forgot-password', [APIAuthController::class, 'forgotPassword']);
    Route::post('verify-reset-otp', [APIAuthController::class, 'verifyResetOtp']);
    Route::post('reset-password', [APIAuthController::class, 'resetPassword']);

    // Refresh token route
    Route::post('refresh-token', [APIAuthController::class, 'refreshToken'])->name('refresh-token');

    // Protected routes
    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::get('profile', [APIAuthController::class, 'profile'])->name('profile');
        Route::post('profile', [APIAuthController::class, 'updateProfile'])->name('update-profile');
        Route::delete('profile-picture', [APIAuthController::class, 'removeProfilePicture'])->name('remove-profile-picture');
        Route::post('change-password', [APIAuthController::class, 'changePassword'])->name('change-password');
        Route::post('logout', [APIAuthController::class, 'logout'])->name('logout');
    });
});
