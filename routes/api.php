<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\TaxCategoryController;
use App\Http\Controllers\API\AddressController;
use App\Http\Controllers\API\AuthController as APIAuthController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\API\SubscriberController;
use App\Http\Controllers\API\WishlistController;
use App\Http\Controllers\Webhook\PaymentWebhookController;

Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Authentication token is require to access this api.'], 401);
})->name('login');

// Auth
Route::prefix('admin')->group(function () {
    // Public Routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/send-reset-otp', [AuthController::class, 'sendResetOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/registered-users', [AuthController::class, 'getRegisteredUsers']);
    Route::get('/registered-users/{id}', [AuthController::class, 'getUserDetails']);
    // Protected Routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/update-user-status/{id}', [AuthController::class, 'toggleUserStatus']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/update', [AuthController::class, 'update']);
    });
});


// Header menu
Route::prefix('header')->group(function () {
    // Public routes
    Route::get('/', [MenuController::class, 'index']);
    // Protected routes for admin
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
    // Protected routes for admin
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
    // Protected routes for admin
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
    // Public route
    Route::post('/', [ContactController::class, 'store']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
    });
});

// Newsletters
Route::prefix('subscribers')->group(function () {
    // Public route
    Route::post('/', [SubscriberController::class, 'store']);
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [SubscriberController::class, 'index']);
        Route::delete('/{email}', [SubscriberController::class, 'destroy']);
    });
});
Route::prefix('products')->group(function () {
    // Public routes with optional authentication
    Route::middleware('optional.auth:sanctum')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/slug/{slug}', [ProductController::class, 'showBySlug']);
        Route::get('/code/{code}', [ProductController::class, 'showByCode']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::get('/category/{categoryId}', [ProductController::class, 'productsByCategory']);
    });

    // Admin protected routes
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::delete('/{product}/images', [ProductController::class, 'deleteImages']);
        Route::post('/{product}/stock', [ProductController::class, 'updateStock']);
        Route::post('/{product}/toggle-publish', [ProductController::class, 'togglePublish']);
    });
});
Route::prefix('tax-categories')->group(function () {
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        // Tax Category Routes
        Route::get('/', [TaxCategoryController::class, 'index']);
        Route::get('/show/{id}', [TaxCategoryController::class, 'show']);
        // Route::get('/get-all', [TaxCategoryController::class, 'all']);
        // Route::get('/stats', [TaxCategoryController::class, 'stats']);
        Route::post('/', [TaxCategoryController::class, 'store']);
        Route::post('/update/{id}', [TaxCategoryController::class, 'update']);
        // Route::delete('/delete/{id}', [TaxCategoryController::class, 'destroy']);
        // Route::post('/bulk-delete', [TaxCategoryController::class, 'bulkDelete']);
    });
});

Route::group(['prefix' => 'user'], function () {
    // Public routes (no authentication required)
    Route::post('send-otp', [APIAuthController::class, 'sendOtp']);
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp']);
    Route::post('confirm_registration', [APIAuthController::class, 'completeRegistration']);
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


Route::prefix('wishlist')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/add', [WishlistController::class, 'add']);
    Route::post('/remove', [WishlistController::class, 'remove']);
    Route::post('/toggle', [WishlistController::class, 'toggle']);
    // Route::get('/check/{productId}', [WishlistController::class, 'check']);
});

Route::prefix('cart')->group(function () {
    // Public routes (for both guest and authenticated users)
    Route::get('/', [CartController::class, 'index']);
    Route::get('/count', [CartController::class, 'count']);
    Route::post('/add', [CartController::class, 'add']);
    Route::post('/update/{itemId}', [CartController::class, 'update']);
    Route::delete('/remove/{itemId}', [CartController::class, 'remove']);
    Route::delete('/clear', [CartController::class, 'clear']);

    // Merge guest cart with user cart (after login)
    Route::post('/merge', [CartController::class, 'mergeCart'])->middleware('auth:sanctum');
});
Route::middleware('auth:sanctum')->group(function () {
    // Address Management
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::post('/', [AddressController::class, 'store']);
        // Route::get('/default', [AddressController::class, 'getDefault']);
        // Route::get('/billing', [AddressController::class, 'getBillingAddresses']);
        // Route::get('/delivery', [AddressController::class, 'getDeliveryAddresses']);
        Route::post('/{id}', [AddressController::class, 'update']);
        Route::post('/{id}/default', [AddressController::class, 'setDefault']);
        Route::delete('/{id}', [AddressController::class, 'destroy']);
    });
});


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/checkout/summary', [CheckoutController::class, 'summary']);
    Route::post('/checkout/apply-coins', [CheckoutController::class, 'applyCoins']);
    Route::post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    Route::get('/order/history', [OrderController::class, 'history']);
    Route::get('/order/{id}', [OrderController::class, 'show']);
});

Route::post('/webhook/payment', [PaymentWebhookController::class, 'handle']);
