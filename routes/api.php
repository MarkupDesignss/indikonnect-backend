<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\TaxCategoryController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\API\AddressController;
use App\Http\Controllers\API\AuthController as APIAuthController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\API\CheckoutController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\FooterController;
use App\Http\Controllers\API\HeritageSiteController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\ProductReviewController;
use App\Http\Controllers\API\ReelController;
use App\Http\Controllers\API\ReturnController;
use App\Http\Controllers\API\ReviewController;
use App\Http\Controllers\API\ShippingMethodController;
use App\Http\Controllers\API\SubscriberController;
use App\Http\Controllers\API\UserDashboardController;
use App\Http\Controllers\API\WishlistController;
use App\Http\Controllers\API\Webhook\RazorpayWebhookController;

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

        //Payout Run routes
        Route::get('/payouts', [PayoutController::class, 'index']);
        Route::post('/payouts', [PayoutController::class, 'store']);
        Route::get('/payouts/{id}', [PayoutController::class, 'show']);
        Route::post('/payouts/{id}/release', [PayoutController::class, 'release']);
        Route::post('/payouts/entries/{entryId}/hold', [PayoutController::class, 'holdEntry']);
        Route::get('/payouts/{id}/export', [PayoutController::class, 'export']);

        // Payout Notifications
        Route::post('/payouts/{id}/notify', [PayoutController::class, 'sendNotifications']);
        
        // Commission Reconciliation Report
        Route::get('/reconciliation', [ReconciliationController::class, 'index']);
        Route::get('/reconciliation/export', [ReconciliationController::class, 'export']);
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
    // Protected routes for admin
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
        Route::post('/bulk-delete', [ContactController::class, 'bulkDelete']);
    });
    Route::post('/', [ContactController::class, 'store']);
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
        Route::get('/trending', [ProductController::class, 'trending']);
        Route::get('/slug/{slug}', [ProductController::class, 'showBySlug']);
        Route::get('/code/{code}', [ProductController::class, 'showByCode']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::post('/update/{id}', [ProductController::class, 'update']);
        Route::delete('/images/{id}', [ProductController::class, 'deleteImages']);
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

Route::post('/products-deal-of-the-day/{id}', [ProductController::class, 'markAsDealOfTheDay']);
Route::delete('/products-deal-of-the-day/{id}', [ProductController::class, 'removeDealOfTheDay']);
Route::get('/products-deal-of-the-day', [ProductController::class, 'getDealOfTheDayProducts']);
Route::get('/products-top-discounted', [ProductController::class, 'getTopDiscountedProducts']);


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


// Distributer and user
Route::prefix('distributor')->group(function () {
    Route::post('check-status', [APIAuthController::class, 'checkUserStatus']);

    // OTP endpoints (separate for phone and email)
    Route::post('send-otp', [APIAuthController::class, 'sendVerificationOtp']);
    Route::post('verify-phone-otp', [APIAuthController::class, 'verifyPhoneOtp']);
    Route::post('verify-email-otp', [APIAuthController::class, 'verifyEmailOtp']);
    Route::post('step1-personal', [APIAuthController::class, 'distributorStep1Personal']);
    Route::post('step2-sponsor', [APIAuthController::class, 'distributorStep2Sponsor']);
    Route::post('step3-aadhaar', [APIAuthController::class, 'distributorStep3Aadhaar']);
    Route::post('step4-pan', [APIAuthController::class, 'distributorStep4Pan']);
    Route::post('step5-bank', [APIAuthController::class, 'distributorStep5Bank']);
    Route::post('step6-location', [APIAuthController::class, 'distributorStep6Location']);
    Route::post('step7-submit', [APIAuthController::class, 'distributorStep7Submit']);
    // Route::post('step-data', [APIAuthController::class, 'getStepData']);
    Route::get('/step-data/{step}/{identifier}', [APIAuthController::class, 'getStepData']);
    Route::post('progress', [APIAuthController::class, 'getDistributorProgress']);
    Route::post('login', [APIAuthController::class, 'distributorLogin']);
});

Route::group(['prefix' => 'distributor'], function () {
    Route::post('forgot-password', [APIAuthController::class, 'forgotPassword']);
    Route::post('verify-reset-otp', [APIAuthController::class, 'verifyResetOtp']);
    Route::post('reset-password', [APIAuthController::class, 'resetPassword']);
});

Route::group(['prefix' => 'user'], function () {
    // Public routes (no authentication required)
    Route::post('send-otp', [APIAuthController::class, 'sendOtp']);
    Route::post('verify-otp', [APIAuthController::class, 'verifyOtp']);
    Route::post('confirm_registration', [APIAuthController::class, 'completeCustomerRegistration']);
    Route::post('resend-otp', [APIAuthController::class, 'resendOtp']);

    Route::post('login', [APIAuthController::class, 'login']);
    Route::post('verify-login-otp', [APIAuthController::class, 'verifyLoginOtp']);

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
    Route::post('/move-to-cart', [WishlistController::class, 'moveToCart']);
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
    Route::get('/checkout/summary', [CheckoutController::class, 'summary']);
    Route::post('/checkout/apply-coins', [CheckoutController::class, 'applyCoins']);
    Route::post('/checkout/place-order', [CheckoutController::class, 'placeOrder']);
    Route::get('/order/history', [OrderController::class, 'history']);
    Route::get('/order/{id}', [OrderController::class, 'show']);
    Route::post('/checkout/apply-coupon', [CheckoutController::class, 'applyCoupon']);
    Route::post('/checkout/apply-shipping', [CheckoutController::class, 'applyShipping']);
    Route::post('/checkout/remove-coupon', [CheckoutController::class, 'removeCoupon']);
});

// Public routes
Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);
Route::get('/products/{product}/reviews/average', [ReviewController::class, 'getAverageRating']);

// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/reviews/{product}', [ReviewController::class, 'showUserReview']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

    // Get user's all reviews
    Route::get('/user/reviews', [ReviewController::class, 'userIndex']);
});
Route::post('/webhook/razorpay', [RazorpayWebhookController::class, 'handle']);


Route::prefix('shipping-methods')->group(function () {
    Route::get('/', [ShippingMethodController::class, 'index']);
    Route::post('/', [ShippingMethodController::class, 'store']);
    Route::get('/{id}', [ShippingMethodController::class, 'show']);
    Route::post('/{id}', [ShippingMethodController::class, 'update']);
    Route::delete('/{id}', [ShippingMethodController::class, 'destroy']);
});


Route::prefix('coupons')->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::post('/', [CouponController::class, 'store']);
    Route::get('/{id}', [CouponController::class, 'show']);
    Route::post('/{id}', [CouponController::class, 'update']);
    Route::delete('/{id}', [CouponController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders/confirmed/{order_reference}', [OrderController::class, 'getConfirmedOrder']);
    // Or use a more generic route
    Route::get('/my-orders', [OrderController::class, 'getOrder']);
    Route::post('/orders/{orderReference}/cancel', [OrderController::class, 'cancel']);
});

Route::get('/orders/statuses', [OrderController::class, 'statuses']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/dashboard', [UserDashboardController::class, 'dashboard']);
    Route::get('/invoice/order/{orderId}', [InvoiceController::class, 'getInvoiceByOrder']);
});


// routes/api.php
Route::prefix('product')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/{productId}/reviews', [ProductReviewController::class, 'index']);
        Route::post('/{productId}/reviews', [ProductReviewController::class, 'store']);
        Route::get('/{productId}/reviews/{reviewId}', [ProductReviewController::class, 'show']);
        Route::put('/{productId}/reviews/{reviewId}', [ProductReviewController::class, 'update']);
        Route::delete('/{productId}/reviews/{reviewId}', [ProductReviewController::class, 'destroy']);
    });
});


Route::prefix('reels')->group(function () {
    Route::get('/', [ReelController::class, 'index']);
    Route::post('/', [ReelController::class, 'store']);
    Route::get('/{id}', [ReelController::class, 'show']);
    Route::post('/{id}', [ReelController::class, 'update']);
    Route::delete('/{id}', [ReelController::class, 'destroy']);
    Route::get('/product/{productId}', [ReelController::class, 'getByProduct']);
});

// Heritage Sites Routes
Route::prefix('heritage')->group(function () {
    // Get all sites with optional filters
    Route::get('/', [HeritageSiteController::class, 'index']);
    Route::get('/{id}', [HeritageSiteController::class, 'show']);
    Route::post('/', [HeritageSiteController::class, 'store']);
    Route::put('/{id}', [HeritageSiteController::class, 'update']);
    Route::delete('/{id}', [HeritageSiteController::class, 'destroy']);
});

Route::prefix('footer')->group(function () {
    Route::get('/', [FooterController::class, 'index']);
    Route::post('/', [FooterController::class, 'store']);
    Route::put('/update', [FooterController::class, 'update']);
});

Route::middleware('auth:sanctum')->group(function () {
    // User routes
    Route::get('/returns/eligibility', [ReturnController::class, 'eligibility']);
    Route::post('/returns/initiate', [ReturnController::class, 'initiate']);
    Route::get('/returns/my-returns', [ReturnController::class, 'myReturns']);
    Route::get('/returns/{id}', [ReturnController::class, 'show']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/returns', [ReturnController::class, 'adminIndex']);
        Route::get('/returns/{id}', [ReturnController::class, 'adminShow']);
        Route::post('/returns/{id}/approve', [ReturnController::class, 'adminApprove']);
        Route::post('/returns/{id}/reject', [ReturnController::class, 'adminReject']);
        Route::post('/returns/{id}/received', [ReturnController::class, 'adminMarkReceived']);
        Route::post('/returns/{id}/complete', [ReturnController::class, 'adminComplete']);
        Route::post('/returns/{id}/refund', [ReturnController::class, 'adminRefund']);
    });
});
